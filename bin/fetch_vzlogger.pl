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
#
# Aufrufe von Hand:
#   fetch_vzlogger.pl            ein Durchlauf
#   fetch_vzlogger.pl --themen   nur ausgeben, welche Feldnamen fuer die
#                                eingestellten Kanaele entstuenden. Kein
#                                Geraetekontakt, kein Schreibvorgang.
#                                Der Reiter Test ruft das auf und haelt die
#                                Antwort gegen seine eigene Liste - ein
#                                Vergleich zweier Listen, die beide aus PHP
#                                stammen, misst nichts.
#   fetch_vzlogger.pl --roh      die Antwort der vzlogger-HTTP-Schnittstelle
#                                je Kanal ausgeben: UUID, OBIS-Kennzahl,
#                                Feldname und der ROHE Wert, so wie vzlogger
#                                ihn liefert. LIEST nur, schreibt nichts -
#                                keine Datendatei, kein MQTT, kein UDP, und
#                                der Umlaufzaehler wird nicht weitergedreht.
#
#                                Wofuer: der Feldkatalog bin/sm_felder.json
#                                fuehrt je Feld ein einheit_vz, und das ist
#                                LEER - vzlogger rechnet nicht um, und ohne
#                                Zaehler war nicht zu entscheiden, welche
#                                Einheit herauskommt. Genau das beantwortet
#                                dieser Schalter an einer laufenden Anlage.

use LoxBerry::System;
use LoxBerry::JSON;
use IO::Socket;
use JSON::PP qw(decode_json);
use warnings;
use strict;

my $psubfolder = $lbpplugindir;
my $vzcfgfile  = "$lbpconfigdir/vzlogger.json";

# Ein unbekannter Schalter darf nicht stillschweigend durchfallen und in
# einem Durchlauf landen: wer von Hand aufruft, soll bei einem Tippfehler
# eine Antwort sehen.
my $nur_themen = 0;
my $nur_roh    = 0;
foreach my $a ( @ARGV ) {
	next if $a !~ /^--/;
	if ( $a eq "--themen" ) { $nur_themen = 1; next; }
	if ( $a eq "--roh" )    { $nur_roh    = 1; next; }
	print STDERR "Unbekannter Schalter: $a\n";
	exit 2;
}

# Not configured -> nothing to do
exit 0 if !-e $vzcfgfile;

my $jsonobj = LoxBerry::JSON->new();
my $cfg = $jsonobj->open(filename => $vzcfgfile, readonly => 1);
exit 0 if !$cfg;
exit 0 if !$cfg->{enabled} && !$nur_themen && !$nur_roh;

################################################################
### Die EINE Quelle: bin/sm_felder.json
###
### Bis 2.3.14 stand die Zuordnung OBIS -> Feldname hier als %SM_NAME, und
### die Oberflaeche baute ihre Themen-Tabelle und ihre Loxone-Vorlage nach
### eigenen Regeln. Gemessen am 26.08.2026: die Vorlage legte
### smartmeter_vzlogger_1_0_1_8_0 an, veroeffentlicht wurde
### smartmeter/vzlogger/Consumption_Total_OBIS_1.8.0 - kein einziger der
### erzeugten Eingaenge konnte je einen Wert bekommen.
###
### Ueber die Sprachgrenze hinweg gibt es keine gemeinsame Funktion, also
### eine gemeinsame DATEI. Faellt sie aus, wird das GESAGT und nicht auf
### eine eingebaute Liste zurueckgefallen: eine zweite Wahrheit im
### Rueckfall ist genau der Fehler, den die Datei abstellen soll.
################################################################

my $felderdatei = "$lbhomedir/bin/plugins/$psubfolder/sm_felder.json";
$felderdatei = "$lbpbindir/sm_felder.json" if !-e $felderdatei && defined $lbpbindir;
my %OBIS;
if ( -e $felderdatei ) {
	my $roh = "";
	if ( open(my $f, "<", $felderdatei) ) { local $/; $roh = <$f>; close($f); }
	my $d = eval { decode_json($roh) };
	if ( $d && $d->{obis} ) { %OBIS = %{$d->{obis}}; }
}
if ( !%OBIS ) {
	&LOG("Die Feldtabelle $felderdatei fehlt oder ist unlesbar. Ohne sie ist die Zuordnung OBIS-Kennzahl zu Feldname nicht bekannt - es wird nichts veroeffentlicht.", "ERROR");
	print STDERR "sm_felder.json fehlt oder ist unlesbar: $felderdatei\n" if $nur_themen;
	exit 1;
}

# Aus einer OBIS-Kennzahl den Feldnamen bilden. Medienkennung "1-0:" und
# Vorschrift "*255" fallen weg; was die Tabelle nicht kennt, wird
# "OBIS_<kurz>". Dieselben zwei Zeilen stehen in sm_obis_feld() der
# PHP-Bibliothek - deshalb liegt die TABELLE in einer gemeinsamen Datei.
sub feldname
{
	my ($obis) = @_;
	my $kurz = $obis;
	$kurz =~ s/^\d+-\d+://;
	$kurz =~ s/\*\d+$//;
	return $OBIS{$kurz} // "OBIS_$kurz";
}

# Die Zaehlernummer. Ohne sie waeren die MQTT-Themen und der UDP-Satz nicht
# vertraeglich mit der klassischen Betriebsart.
my $serial = $cfg->{serial};
$serial = "" if !defined $serial;
$serial =~ s/[^A-Za-z0-9_\-]//g;
$serial = "vzlogger" if $serial eq "";

################################################################
### --themen: nur sagen, was entstuende
################################################################
if ( $nur_themen ) {
	my @namen;
	foreach my $c ( @{$cfg->{channels} || []} ) {
		push @namen, &feldname($c);
	}
	# Genau die Zusaetze, die der Durchlauf unten selbst bildet.
	push @namen, "Last_Update", "Last_UpdateUnix", "Last_UpdateLoxEpoche";
	if ( grep { $_ eq "Total_Power_OBIS_16.7.0" } @namen ) {
		push @namen, "Consumption_CalculatedPower_OBIS_1.99.0",
		             "Delivery_CalculatedPower_OBIS_2.99.0";
	}
	my %gesehen;
	foreach my $n ( @namen ) {
		next if $gesehen{$n}++;
		print "$n\n";
	}
	exit 0;
}

################################################################
### --roh: was vzlogger WIRKLICH liefert
###
### Der eine Zweck ist einheit_vz in bin/sm_felder.json. Solange das Feld
### leer ist, traegt die Loxone-Vorlage auf dem vzLogger-Weg gar keine
### Einheit - das ist ehrlich, aber es ist auch nichts. Hier steht der
### Rohwert, aus dem sich die Einheit ablesen laesst.
###
### Er BEHAUPTET nichts. Die Spalte "Groessenordnung" ist ein Hinweis aus
### dem Zahlenwert, kein Messwert: ein Zaehlerstand um 12345 ist eher kWh,
### einer um 12345678 eher Wh. Wer sie fuer eine Messung haelt, hat sie
### falsch gelesen - massgeblich ist das Datenblatt des Zaehlers.
###
### Es wird NICHTS geschrieben: keine Datendatei, kein MQTT, kein UDP, und
### der Umlaufzaehler bleibt stehen. Ein Messstueck, das den Messgegenstand
### veraendert, misst sich selbst.
################################################################
if ( $nur_roh ) {
	my $port = $cfg->{httpport} || 8083;
	my $roh = `curl -s -m 5 http://127.0.0.1:$port/ 2>/dev/null`;
	if ( !$roh ) {
		print STDERR "Keine Antwort von der vzlogger-Schnittstelle auf Port $port.\n";
		print STDERR "Laeuft vzlogger? Der Reiter vzLogger zeigt den Zustand.\n";
		exit 3;
	}
	my $d = eval { decode_json($roh) };
	if ( !$d || !$d->{data} ) {
		print STDERR "Die Antwort auf Port $port ist kein brauchbares JSON.\n";
		exit 3;
	}
	my %obis_by_uuid;
	%obis_by_uuid = reverse %{$cfg->{uuids}} if $cfg->{uuids};

	printf("%-38s %-14s %-42s %18s  %s\n",
	       "UUID", "OBIS", "Feldname", "Rohwert", "Groessenordnung (Hinweis)");
	print "-" x 132, "\n";
	my $kanaele = 0;
	foreach my $ch ( @{$d->{data}} ) {
		my $uuid = $ch->{uuid} // "";
		my $obis = $obis_by_uuid{$uuid} // "(unbekannt)";
		my $feld = $obis eq "(unbekannt)" ? "(kein Kanal dieser Anlage)" : &feldname($obis);
		if ( !$ch->{tuples} || !@{$ch->{tuples}} ) {
			printf("%-38s %-14s %-42s %18s  %s\n",
			       $uuid, $obis, $feld, "-", "noch kein Wert eingetroffen");
			next;
		}
		my @sortiert = sort { $b->[0] <=> $a->[0] } @{$ch->{tuples}};
		my $wert = $sortiert[0][1];
		$kanaele++;
		printf("%-38s %-14s %-42s %18s  %s\n",
		       $uuid, $obis, $feld, $wert, &groessenordnung($obis, $wert));
	}
	print "\n";
	print "Die letzte Spalte ist ein HINWEIS aus dem Zahlenwert, keine Messung.\n";
	print "Massgeblich ist das Datenblatt des Zaehlers. Wer die Einheit belegt\n";
	print "hat, traegt sie in Werkzeuge/sm_felder_erzeugen.py als einheit_vz ein\n";
	print "und laesst den Erzeuger laufen - dann steht sie auch in der Vorlage.\n";
	printf("\n%d Kanal/Kanaele mit einem Wert.\n", $kanaele);
	exit 0;
}

# Aus einer OBIS-Kennzahl und einem Rohwert einen Hinweis auf die Einheit
# bilden. Bewusst grob und bewusst als Hinweis benannt: die Grenzen sind
# geschaetzt, nicht gemessen.
sub groessenordnung
{
	my ($obis, $wert) = @_;
	return "kein Zahlenwert" if !defined $wert || $wert !~ /^-?[\d.]+$/;
	my $b = abs($wert) + 0;
	my $kurz = $obis;
	$kurz =~ s/^\d+-\d+://;
	$kurz =~ s/\*\d+$//;
	# Zaehlwerke: 1.8.x und 2.8.x
	if ( $kurz =~ /^[12]\.8\./ ) {
		return "0 - noch nichts gezaehlt"      if $b == 0;
		return "eher kWh (Zaehlerstand klein)" if $b < 100000;
		return "eher Wh (Zaehlerstand gross)";
	}
	# Momentanleistung: 16.7.0, 1.7.0, 2.7.0, 15.7.0
	if ( $kurz =~ /^(1|2|15|16)\.7\.0$/ ) {
		return "0 - gerade keine Leistung" if $b == 0;
		return "eher kW (Wert klein)"      if $b < 100;
		return "eher W (Wert gross)";
	}
	return "-";
}

# ---------------------------------------------------------------------------
# Nur ein Lauf gleichzeitig - eine Sperrdatei je DATENBESTAND.
#
# Dieses Skript steht fest im Minutentakt in cron/crontab. Es fragt vzlogger
# ueber HTTP ab und sendet danach ueber MQTT und wahlweise UDP. Haengt eine
# dieser Verbindungen, ueberholt der naechste Cron-Aufruf den vorigen: zwei
# Laeufe schreiben dann dieselbe Datendatei und schicken dieselben Werte
# doppelt an den Miniserver.
#
# Die Sperre heisst seit 2.3.14 daten.lock und nicht mehr
# fetch_vzlogger.lock: bin/fetch.php sperrte fetch.lock und schrieb DIESELBEN
# Dateien - die beiden sperrten sich also gerade nicht gegeneinander. Eine
# Sperrdatei gehoert zum Datenbestand, nicht zum Skript.
#
# LOCK_NB, damit ein laufender Vorgaenger diesen Aufruf sofort beendet -
# ohne Meldung, denn das ist der Normalfall und kein Fehler.
# ---------------------------------------------------------------------------
use Fcntl qw(:flock O_RDWR O_CREAT);
system("mkdir -p /dev/shm/$psubfolder > /dev/null 2>&1");
my $sperrdatei = "/dev/shm/$psubfolder/daten.lock";
if ( sysopen(my $SPERRE, $sperrdatei, O_RDWR|O_CREAT, 0644) ) {
	exit 0 if !flock($SPERRE, LOCK_EX|LOCK_NB);
	# Der Griff bleibt bis zum Programmende offen - das Betriebssystem gibt
	# die Sperre dann von selbst frei, auch wenn das Skript abstuerzt.
}

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
			&ZAEHLER_WEITER();
			exit 0;
		}
	}
}

# Poll local vzlogger HTTP API
my $raw = `curl -s -m 5 http://127.0.0.1:$httpport/ 2>/dev/null`;
if ( !$raw ) {
	&LOG("Could not read from vzlogger HTTP API on port $httpport. Is vzlogger running?", "WARN");
	&ZAEHLER_WEITER();
	exit 0;
}

my $data = eval { decode_json($raw) };
if ( !$data || !$data->{data} ) {
	&LOG("Invalid JSON from vzlogger HTTP API.", "WARN");
	&ZAEHLER_WEITER();
	exit 0;
}

# Map uuid -> OBIS id (as stored on save)
my %obis_by_uuid;
if ( $cfg->{uuids} ) {
	%obis_by_uuid = reverse %{$cfg->{uuids}};
}

# Collect latest value per channel
my %werte;
foreach my $ch ( @{$data->{data}} ) {
	my $obis = $obis_by_uuid{$ch->{uuid}} // $ch->{uuid};
	next if !$ch->{tuples} || !@{$ch->{tuples}};
	# Latest tuple: [ timestamp_ms, value, quality ]
	my @sorted = sort { $b->[0] <=> $a->[0] } @{$ch->{tuples}};
	$werte{&feldname($obis)} = $sorted[0][1];
}
if ( !%werte ) {
	&LOG("No readings available (yet).", "INFO");
	&ZAEHLER_WEITER();
	exit 0;
}

# Zeitstempel wie beim klassischen Leser: lesbar, als Unix-Sekunden und als
# Loxone-Epoche (Bezugspunkt 01.01.2009 00:00 Ortszeit).
#
# Last_UpdateUnix ist seit 2.3.14 dabei und der Grund ist der Endpunkt: er
# rechnet daraus das ALTER zur LESEZEIT. Ein Alter, das beim Schreiben
# eingefroren wird, kann einen toten Dienst nicht von einer frischen Messung
# unterscheiden. Geschrieben wird er nur hier, also nur nach einer
# erfolgreichen Messung - der Zeitstempel gehoert zur Messung, nicht zum
# Schreibvorgang.
my @lt = localtime(time());
my $datereadable = sprintf("%02d.%02d.%04d %02d:%02d:%02d",
	$lt[3], $lt[4]+1, $lt[5]+1900, $lt[2], $lt[1], $lt[0]);
use Time::Local;
my $offset = timegm(localtime(time())) - time();
my $epoche_lox = time() - 1230768000 + $offset;
$werte{Last_Update}          = $datereadable;
$werte{Last_UpdateUnix}      = time();
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
# Erst daneben schreiben, dann umbenennen. Ein einfaches ">" kuerzt die
# Datei und fuellt sie neu; wer in diesem Fenster liest - und das tut
# webfrontend/html/index.php fuer den Miniserver - bekommt nichts oder die
# Haelfte. Gemessen: 69,75 % kaputte Lesevorgaenge beim Kuerzen, 0,00 % mit
# temp + rename. rename() ist im selben Dateisystem unteilbar.
my $ziel = "/dev/shm/$psubfolder/$serial.data";
my $tmp  = "$ziel.tmp.$$";
if ( open(my $fh, ">", $tmp) ) {
	print $fh join("\n", @zeilen) . "\n";
	close($fh);
	rename($tmp, $ziel) or do { &LOG("Datendatei liess sich nicht umbenennen: $tmp", "ERROR"); unlink($tmp); };
} else {
	&LOG("Datendatei nicht schreibbar: $tmp", "ERROR");
}

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
$mqtttopic =~ s{^/+|/+$}{}g;
$mqtttopic = "smartmeter" if $mqtttopic eq "";
if ( $sendmqtt ) {
	my @paare = map { [ $_, $werte{$_} ] } sort keys %werte;
	&SEND_MQTT("$mqtttopic/$serial", \@paare);
}

# Das Lebenszeichen eine Stelle weiter - bei JEDEM abgeschlossenen Durchlauf.
&ZAEHLER_WEITER();

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
	my $datei = "/dev/shm/$psubfolder/vzlogger_fetch.log";
	# Kappung nach dem Hausmuster: ab 500 kB bleiben die letzten 200 Zeilen.
	# log/plugins und /dev/shm liegen beide auf einer Ramdisk - eine
	# wachsende Datei frisst dort Arbeitsspeicher, nicht Plattenplatz.
	if ( -s $datei && (-s $datei) > 512000 ) {
		if ( open(my $r, "<", $datei) ) {
			my @alle = <$r>;
			close($r);
			my @rest = @alle > 200 ? @alle[-200..-1] : @alle;
			if ( open(my $w, ">", $datei) ) { print $w @rest; close($w); }
		}
	}
	if ( open(my $fh, ">>", $datei) ) {
		print $fh "$stamp <$type> $message\n";
		close($fh);
	}

	return();
}

################################
### SUB: umlaufender Zaehler
###
### 0..999, danach wieder 0. -1 heisst "noch nie gelaufen" und wird nur vom
### Leser gebildet, nicht hier. Er liegt auf der Ramdisk neben den
### Datendateien; dass er nach einem Neustart bei -1 beginnt, ist die
### richtige Aussage.
###
### Warum ein Zaehler und nicht nur ein Zeitstempel: ein Raspberry Pi hat
### keine Echtzeituhr. Nach dem Booten steht er in der Vergangenheit, und
### sobald NTP greift, springt die Zeit.
################################

sub ZAEHLER_WEITER
{
	my $datei = "/dev/shm/$psubfolder/zaehler";
	my $alt = -1;
	if ( -e $datei && open(my $r, "<", $datei) ) {
		my $w = <$r>;
		close($r);
		$w = "" if !defined $w;
		$w =~ s/\s//g;
		$alt = $w + 0 if $w =~ /^\d{1,3}$/;
	}
	my $neu = ($alt < 0) ? 0 : (($alt + 1) % 1000);
	my $tmp = "$datei.tmp.$$";
	if ( open(my $w, ">", $tmp) ) {
		print $w $neu;
		close($w);
		rename($tmp, $datei) or unlink($tmp);
	}
	return $neu;
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
	my $fehl = 0;
	foreach my $p ( @{$paare} ) {
		my ($key, $value) = @{$p};
		next if !defined $key || $key eq "";
		next if !defined $value;
		$key =~ s/[^A-Za-z0-9_\.\-]/_/g;
		# Der Wert wird gesaeubert - das Gateway liest ZEILENWEISE und
		# trennt Thema und Wert am Leerraum. Bis 2.3.14 hatte diese
		# Saeuberung nur bin/fetch.php, und Last_Update ist ein Text mit
		# Leerzeichen. Zwei Wege, die dieselben Werte tragen sollen,
		# behandeln sie gleich.
		$value = &SAEUBERN($value);
		next if $value eq "";
		if ( $sock->send("publish $prefix/$key $value") ) { $anzahl++; }
		else { $fehl++; }
	}
	close($sock);
	# Ein Zaehler zaehlt Zustellungen, nicht Schleifendurchlaeufe.
	&LOG("MQTT: $anzahl Werte an $prefix"
	   . ($fehl ? ", $fehl gescheitert" : "")
	   . " (Gateway-Relay 127.0.0.1:$udpin)", $fehl ? "WARN" : "OK");

	return;

}

# Gegenstueck zu smg_wert_saeubern() in bin/sm_gemein.php.
sub SAEUBERN
{
	my ($v) = @_;
	$v = "" if !defined $v;
	$v =~ s/[\r\n\t]/ /g;
	$v =~ s/ {2,}/ /g;
	$v =~ s/^\s+|\s+$//g;
	return $v;
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
