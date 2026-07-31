#!/usr/bin/perl

# fetch_vzlogger.pl - Part of the LoxBerry Smartmeter Plugin (vzLogger mode)
#
# Polls the local vzlogger HTTP API (see "local" section of vzlogger.conf),
# writes the latest readings to /dev/shm/<plugin>/<serial>.data in the same
# scheme as the classic reader (SERIAL:key:value) and sends
# them via UDP to all configured Miniservers.
#
# Runs every minute via cron - exits immediately if vzLogger mode is
# disabled, so it produces no load in legacy-only setups.

use LoxBerry::System;
use LoxBerry::JSON;
use IO::Socket;
use JSON::PP qw(decode_json);
use warnings;
use strict;

my $psubfolder = $lbpplugindir;
my $vzcfgfile  = "$lbpconfigdir/vzlogger.json";

# Not configured -> nothing to do
exit 0 if !-e $vzcfgfile;

my $jsonobj = LoxBerry::JSON->new();
my $cfg = $jsonobj->open(filename => $vzcfgfile, readonly => 1);
exit 0 if !$cfg || !$cfg->{enabled};

my $httpport = $cfg->{httpport} || 8083;

################################################################
### Waechter: laeuft vzlogger ueberhaupt?
###
### Bis 1.8 wurde vzlogger nur beim Speichern in der Oberflaeche
### gestartet. Nach einem Plugin-Update, einem Neustart oder einem
### Absturz lief deshalb nichts mehr, und in der Oberflaeche stand
### "Betriebsart eingeschaltet" neben "Prozess laeuft nicht" - man
### musste erst aus- und wieder einschalten. Diese Minuten-Cron
### holt das jetzt selbst nach.
################################################################

my $vzconf = "$lbpconfigdir/vzlogger.conf";
if ( -e $vzconf && !&vz_laeuft($vzconf) ) {
	my $bin = &vz_binary();
	if ( !$bin ) {
		&LOG("vzlogger ist eingeschaltet, aber es ist kein lauffaehiges vzlogger installiert.", "WARN");
	} else {
		my $log = "/dev/shm/$psubfolder/vzlogger.log";
		system("mkdir -p /dev/shm/$psubfolder > /dev/null 2>&1");
		system("nohup \"$bin\" -c \"$vzconf\" >> \"$log\" 2>&1 &");
		sleep 2;
		if ( &vz_laeuft($vzconf) ) {
			&LOG("vzlogger lief nicht und wurde vom Waechter gestartet ($bin).", "OK");
		} else {
			&LOG("vzlogger lief nicht und liess sich auch nicht starten. Einzelheiten im vzlogger-Protokoll.", "WARN");
			exit 0;
		}
	}
}

# Poll local vzlogger HTTP API
my $raw = `curl -s -m 5 http://127.0.0.1:$httpport/ 2>/dev/null`;
if ( !$raw ) {
	&LOG("Could not read from vzlogger HTTP API on port $httpport. Is vzlogger running?", "WARN");
	exit 0;
}

my $data = eval { decode_json($raw) };
if ( !$data || !$data->{data} ) {
	&LOG("Invalid JSON from vzlogger HTTP API.", "WARN");
	exit 0;
}

# Map uuid -> OBIS id (as stored on save)
my %obis_by_uuid;
if ( $cfg->{uuids} ) {
	%obis_by_uuid = reverse %{$cfg->{uuids}};
}

# Kennung des vzlogger -> Schluesselname des klassischen Lesers.
# Damit senden beide Betriebsarten dasselbe Schema, und die virtuellen
# Eingaenge im Miniserver bleiben beim Wechsel unveraendert.
my %SM_NAME = (
	"1.8.0"  => "Consumption_Total_OBIS_1.8.0",
	"1.8.1"  => "Consumption_Tarif1_OBIS_1.8.1",
	"1.8.2"  => "Consumption_Tarif2_OBIS_1.8.2",
	"2.8.0"  => "Delivery_Total_OBIS_2.8.0",
	"2.8.1"  => "Delivery_Tarif1_OBIS_2.8.1",
	"2.8.2"  => "Delivery_Tarif2_OBIS_2.8.2",
	"16.7.0" => "Total_Power_OBIS_16.7.0",
);

# Collect latest value per channel
my %werte;
foreach my $ch ( @{$data->{data}} ) {
	my $obis = $obis_by_uuid{$ch->{uuid}} // $ch->{uuid};
	next if !$ch->{tuples} || !@{$ch->{tuples}};
	# Latest tuple: [ timestamp_ms, value, quality ]
	my @sorted = sort { $b->[0] <=> $a->[0] } @{$ch->{tuples}};
	# Medienkennung "1-0:" und Vorschrift "*255" abschneiden
	my $kurz = $obis;
	$kurz =~ s/^\d+-\d+://;
	$kurz =~ s/\*\d+$//;
	my $name = $SM_NAME{$kurz} // "OBIS_$kurz";
	$werte{$name} = $sorted[0][1];
}
if ( !%werte ) {
	&LOG("No readings available (yet).", "INFO");
	exit 0;
}

# Zaehlernummer. Ohne sie waeren die MQTT-Themen und der UDP-Satz nicht
# vertraeglich mit der klassischen Betriebsart.
my $serial = $cfg->{serial};
$serial = "" if !defined $serial;
$serial =~ s/[^A-Za-z0-9_\-]//g;
$serial = "vzlogger" if $serial eq "";

# Zeitstempel wie beim klassischen Leser: lesbar und als Loxone-Epoche
# (Bezugspunkt 01.01.2009 00:00 Ortszeit).
my @lt = localtime(time());
my $datereadable = sprintf("%02d.%02d.%04d %02d:%02d:%02d",
	$lt[3], $lt[4]+1, $lt[5]+1900, $lt[2], $lt[1], $lt[0]);
use Time::Local;
my $offset = timegm(localtime(time())) - time();
my $epoche_lox = time() - 1230768000 + $offset;
$werte{Last_Update}          = $datereadable;
$werte{Last_UpdateLoxEpoche} = $epoche_lox;

# Die beiden kalkulierten Leistungen kennt vzlogger nicht. Der klassische
# Leser rechnet sie aus dem Zaehlerfortschritt; hier werden sie aus der
# Momentanleistung 16.7.0 abgeleitet - Vorzeichen positiv ist Bezug,
# negativ ist Einspeisung. Nur damit vorhandene Auswertungen im Miniserver
# beim Wechsel der Betriebsart nicht ins Leere laufen.
if ( defined $werte{"Total_Power_OBIS_16.7.0"} ) {
	my $p = $werte{"Total_Power_OBIS_16.7.0"} + 0;
	$werte{"Consumption_CalculatedPower_OBIS_1.99.0"} = $p > 0 ? $p  : 0;
	$werte{"Delivery_CalculatedPower_OBIS_2.99.0"}    = $p < 0 ? -$p : 0;
}

# Datei im selben Schema wie der klassische Leser: SERIAL:Schluessel:Wert
my @zeilen = map { "$serial:$_:" . $werte{$_} } sort keys %werte;
system("mkdir -p /dev/shm/$psubfolder > /dev/null 2>&1");
open(my $fh, ">", "/dev/shm/$psubfolder/$serial.data");
print $fh join("\n", @zeilen) . "\n";
close($fh);

&LOG("Readings: " . join("; ", @zeilen), "INFO");

# Werte per MQTT veroeffentlichen (Hausstandard).
# Eingestellt wird MQTT an genau einer Stelle - im Reiter MQTT, der in die
# allgemeine Plugin-Konfiguration schreibt. Hier wird nur gelesen.
my ($sendmqtt, $mqtttopic) = (1, "smartmeter");
if ( -e "$lbpconfigdir/smartmeter.cfg" ) {
	if ( open(my $c, "<", "$lbpconfigdir/smartmeter.cfg") ) {
		while ( my $z = <$c> ) {
			$sendmqtt  = ($1 ? 1 : 0) if $z =~ /^\s*SENDMQTT\s*=\s*(\S+)/;
			$mqtttopic = $1           if $z =~ /^\s*MQTTTOPIC\s*=\s*(\S+)/;
		}
		close($c);
	}
}
$mqtttopic =~ s/^["']|["']$//g;
$mqtttopic = "smartmeter" if $mqtttopic eq "";
if ( $sendmqtt ) {
	my $prefix = $mqtttopic;
	my @paare = map { [ $_, $werte{$_} ] } sort keys %werte;
	&SEND_MQTT("$prefix/$serial", \@paare);
}

# Send via UDP to all Miniservers
exit 0 if !$cfg->{sendudp};
my $udpport = $cfg->{udpport} || 7000;
my $udpstring = join("; ", @zeilen) . "; ";

my %ms = LoxBerry::System::get_miniservers();
foreach my $msno ( sort keys %ms ) {
	next if !$ms{$msno}{IPAddress};
	my $sock = IO::Socket::INET->new(
		Proto    => 'udp',
		PeerPort => $udpport,
		PeerAddr => $ms{$msno}{IPAddress},
	);
	if ( !$sock ) {
		&LOG("Could not create UDP socket for " . $ms{$msno}{Name} . ": $!", "WARN");
		next;
	}
	$sock->send($udpstring);
	&LOG("Send OK to " . $ms{$msno}{Name} . " (" . $ms{$msno}{IPAddress} . ":$udpport)", "OK");
}

exit 0;

################################
### SUB: Log
################################

sub LOG
{
	my $message = shift;
	my $type = uc(shift // "INFO");

	(my $sec,my $min,my $hour,my $mday,my $mon,my $year) = localtime();
	$year += 1900; $mon += 1;
	my $stamp = sprintf("%04d-%02d-%02d %02d:%02d:%02d", $year, $mon, $mday, $hour, $min, $sec);

	system("mkdir -p /dev/shm/$psubfolder > /dev/null 2>&1");
	open(my $fh, ">>", "/dev/shm/$psubfolder/vzlogger_fetch.log");
	print $fh "$stamp <$type> $message\n";
	close($fh);

	return();
}

################################
### MQTT (LoxBerry MQTT Gateway, UDP-Relay)
################################

# Hausstandard: Werte gehen per MQTT an den Miniserver. Wir liefern sie am
# UDP-Relay des Gateways ab, das Gateway veroeffentlicht daraus MQTT.
# Kein zusaetzliches Perl-Modul noetig.
sub SEND_MQTT {

	my ($prefix, $paare) = @_;

	return if !$prefix;

	# UDP-In-Port des Gateways aus der allgemeinen Konfiguration lesen.
	# Beide Schreibweisen pruefen - die Gateway-Konfiguration ist uneinheitlich.
	my $genfile = "$lbhomedir/config/system/general.json";
	return if !-e $genfile;
	my $json = "";
	if ( open(my $g, "<", $genfile) ) { local $/; $json = <$g>; close($g); }
	my $udpin = 0;
	if ( $json =~ /"Udpinport"\s*:\s*"?(\d+)"?/i ) { $udpin = $1; }
	if ( !$udpin && $json =~ /"udpinport"\s*:\s*"?(\d+)"?/i ) { $udpin = $1; }
	if ( !$udpin ) {
		&LOG("MQTT: Kein UDP-In-Port des MQTT Gateways gefunden - uebersprungen.", "WARN");
		return;
	}

	my $sock = IO::Socket::INET->new(
		Proto    => 'udp',
		PeerPort => $udpin,
		PeerAddr => '127.0.0.1',
	);
	if ( !$sock ) {
		&LOG("MQTT: UDP-Relay nicht erreichbar: $!", "WARN");
		return;
	}

	my $anzahl = 0;
	foreach my $p ( @{$paare} ) {
		my ($key, $value) = @{$p};
		next if !defined $key || $key eq "";
		next if !defined $value || $value eq "";
		$key =~ s/[^A-Za-z0-9_\.\-]/_/g;
		my $msg = "publish $prefix/$key $value";
		$sock->send($msg);
		$anzahl++;
	}
	close($sock);
	&LOG("MQTT: $anzahl Werte an $prefix (Gateway-Relay 127.0.0.1:$udpin)", "OK");

	return;

}

################################
### Waechter-Hilfsfunktionen
################################

# Laeuft ein vzlogger mit UNSERER Konfiguration? pgrep -f findet auch die
# aufrufende Shell, deren Befehlszeile das Suchmuster enthaelt - deshalb wird
# jede Fundstelle gegen den echten Programmnamen geprueft.
sub vz_laeuft
{
	my ($conf) = @_;
	my $raw = `pgrep -f -- "-c $conf" 2>/dev/null`;
	return 0 if !defined $raw;
	foreach my $p ( split(/\s+/, $raw) ) {
		next if $p !~ /^\d+$/;
		my $c = `ps -p $p -o comm= 2>/dev/null`;
		next if !defined $c;
		chomp $c;
		return 1 if $c =~ /vzlogger/i;
	}
	return 0;
}

# Nur ein systemweit installiertes vzlogger - seit 1.6 liefert das Plugin
# keines mehr mit.
sub vz_binary
{
	my $bin = `command -v vzlogger 2>/dev/null`;
	return "" if !defined $bin;
	chomp $bin;
	return "" if !$bin || !-x $bin;
	my $out = `"$bin" --version 2>&1`;
	$out = "" if !defined $out;
	my $rc = $? >> 8;
	return "" if $out =~ /not found|No such file|cannot execute|Exec format error/i;
	return $bin if $rc == 0 || $out =~ /^\s*vzlogger\s+[\d.]/i;
	return "";
}
