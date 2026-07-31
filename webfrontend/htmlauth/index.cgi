#!/usr/bin/perl

# Copyright 2019 Michael Schlenstedt, michael@loxberry.de
#
# Licensed under the Apache License, Version 2.0 (the "License");
# you may not use this file except in compliance with the License.
# You may obtain a copy of the License at
#
#     http://www.apache.org/licenses/LICENSE-2.0
#
# Unless required by applicable law or agreed to in writing, software
# distributed under the License is distributed on an "AS IS" BASIS,
# WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
# See the License for the specific language governing permissions and
# limitations under the License.


##########################################################################
# Modules
##########################################################################

use CGI;
use LoxBerry::System;
use LoxBerry::JSON; # Available with LoxBerry 2.0
use LoxBerry::Log;
use Config::Simple;
use Digest::MD5 qw(md5_hex);
use warnings;
use strict;

##########################################################################
# Variables
##########################################################################

my $sm_log;

# Read Form
my $sm_cgi = CGI->new;
my $sm_q = $sm_cgi->Vars;

my $sm_version = LoxBerry::System::pluginversion();
my $sm_template;

# Language Phrases
my %sm_L;

# vzlogger config files
my $sm_vzcfgfile  = "$lbpconfigdir/vzlogger.json";
my $sm_vzconffile = "$lbpconfigdir/vzlogger.conf";

##########################################################################
# AJAX
##########################################################################

if( $sm_q->{ajax} ) {

	## Handle all ajax requests
	require JSON;
	# require Time::HiRes;
	my %sm_response;
	ajax_header();

	exit;

##########################################################################
# Normal request (not AJAX)
##########################################################################

} else {

	require LoxBerry::Web;

	# Init Template
	$sm_template = HTML::Template->new(
	    filename => "$lbptemplatedir/settings.html",
	    global_vars => 1,
	    loop_context_vars => 1,
	    die_on_bad_params => 0,
	);
	%sm_L = LoxBerry::System::readlanguage($sm_template, "language.ini");

        # Default is vzlogger form
	$sm_q->{form} = "vzlogger" if !$sm_q->{form};

	# Reiterleiste: aktiven Reiter markieren (eine Leiste fuer alle Ansichten)
	$sm_template->param("TAB_" . uc($sm_q->{form}), 1);

	if ($sm_q->{form} eq "vzlogger") {
		&save_vzlogger() if $sm_q->{saveformdata};
		$sm_template->param("VZ_INSTALLOUT", &vz_install()) if $sm_q->{vzinstall};
		&form_vzlogger();
	}
	elsif ($sm_q->{form} eq "mqtt") {
		&save_mqtt() if $sm_q->{saveformdata};
		&form_mqtt();
	}
	elsif ($sm_q->{form} eq "loxone") { &form_loxone() }
	elsif ($sm_q->{form} eq "test")   { &form_test() }
	elsif ($sm_q->{form} eq "log")    { &form_log() }
	else                              { &form_vzlogger() }

	# Print the form
	&form_print();
}

exit;

##########################################################################
# Form: vzlogger
##########################################################################

sub form_vzlogger
{
	$sm_template->param("FORM_VZLOGGER", 1);

	my $sm_jsonobj = LoxBerry::JSON->new();
	my $sm_cfg = $sm_jsonobj->open(filename => $sm_vzcfgfile, readonly => 1);

	# Defaults
	my $sm_channels = "1-0:1.8.0\n1-0:2.8.0\n1-0:16.7.0";
	$sm_channels = join("\n", @{$sm_cfg->{channels}}) if $sm_cfg->{channels} && @{$sm_cfg->{channels}};

	$sm_template->param("VZ_ENABLED",  $sm_cfg->{enabled} ? 1 : 0);
	$sm_template->param("VZ_DEVICE",   $sm_cfg->{device}   // "");
	$sm_template->param("VZ_PROTOCOL", $sm_cfg->{protocol} // "sml");
	$sm_template->param("VZ_BAUDRATE", $sm_cfg->{baudrate} // 9600);
	$sm_template->param("VZ_PARITY",   $sm_cfg->{parity}   // "8n1");
	$sm_template->param("VZ_LOCALTIME", (defined $sm_cfg->{localtime} && !$sm_cfg->{localtime}) ? 0 : 1);

	# MQTT gilt fuer beide Betriebsarten und wird im eigenen Reiter
	# eingestellt - hier nur anzeigen.
	my ($sm_man, $sm_mtop) = &mqtt_lesen();
	$sm_template->param("VZ_MQTT_AN",    $sm_man);
	$sm_template->param("VZ_MQTT_TOPIC", $sm_mtop);
	$sm_template->param("VZ_SENDUDP",  defined $sm_cfg->{sendudp} ? ($sm_cfg->{sendudp} ? 1 : 0) : 1);
	$sm_template->param("VZ_UDPPORT",  $sm_cfg->{udpport}  // 7000);
	$sm_template->param("VZ_HTTPPORT", $sm_cfg->{httpport} // 8083);
	$sm_template->param("VZ_CHANNELS", $sm_channels);

	# Connected IR heads
	my @sm_devs = glob("/dev/serial/smartmeter/*");
	my @sm_devloop;
	foreach my $sm_d (@sm_devs) {
		push @sm_devloop, { DEV => $sm_d };
	}
	$sm_template->param("VZ_DEVICES", \@sm_devloop);
	$sm_template->param("VZ_NODEVICE", scalar @sm_devs ? 0 : 1);

	# Binary / running state
	my ($sm_bin, $sm_why) = &vz_binary();
	$sm_template->param("VZ_BINOK", $sm_bin ? 1 : 0);
	$sm_template->param("VZ_BIN", $sm_bin);
	$sm_template->param("VZ_BINWHY", $sm_why);
	$sm_template->param("VZ_RUNNING", &vz_running() ? 1 : 0);
	$sm_template->param("VZ_SAVED", $sm_q->{saveformdata} ? 1 : 0);

	# Diagnose und Protokoll
	$sm_template->param("VZ_DIAG", &vz_diagnose($sm_cfg));
	$sm_template->param("VZ_LOG",  &vz_logtail());

	return();
}

##########################################################################
# Save vzlogger config, regenerate vzlogger.conf, restart vzlogger
##########################################################################

sub save_vzlogger
{
	my $sm_jsonobj = LoxBerry::JSON->new();
	my $sm_cfg = $sm_jsonobj->open(filename => $sm_vzcfgfile);

	$sm_cfg->{enabled}  = $sm_q->{vz_enabled} ? 1 : 0;
	$sm_cfg->{device}   = $sm_q->{vz_device}   // "";
	$sm_cfg->{protocol} = ($sm_q->{vz_protocol} && $sm_q->{vz_protocol} eq "d0") ? "d0" : "sml";
	$sm_cfg->{baudrate} = ($sm_q->{vz_baudrate} && $sm_q->{vz_baudrate} =~ /^\d+$/) ? $sm_q->{vz_baudrate} + 0 : 9600;
	$sm_cfg->{parity}   = ($sm_q->{vz_parity} && $sm_q->{vz_parity} =~ /^(8n1|7n1|7e1|8e1)$/) ? $sm_q->{vz_parity} : "8n1";
	# Zeitstempel des Zaehlers oder Rechner-Uhrzeit? Viele Haushaltszaehler
	# (z. B. Landis+Gyr E220) senden gar keine gestellte Uhr. vzlogger verwirft
	# solche Telegramme dann mit "timestamp before 1990, IGNORING" - der Zaehler
	# wird gelesen, aber kein einziger Wert kommt an. Deshalb ist die
	# Rechner-Uhrzeit hier die Vorgabe.
	$sm_cfg->{localtime} = (defined $sm_q->{vz_localtime} && $sm_q->{vz_localtime} eq "0") ? 0 : 1;
	$sm_cfg->{sendudp}  = $sm_q->{vz_sendudp} ? 1 : 0;
	$sm_cfg->{udpport}  = ($sm_q->{vz_udpport} && $sm_q->{vz_udpport} =~ /^\d+$/) ? $sm_q->{vz_udpport} + 0 : 7000;
	$sm_cfg->{httpport} = ($sm_q->{vz_httpport} && $sm_q->{vz_httpport} =~ /^\d+$/) ? $sm_q->{vz_httpport} + 0 : 8083;

	# OBIS channels: one per line (or comma separated)
	my @sm_channels;
	foreach my $sm_c ( split(/[\r\n,]+/, $sm_q->{vz_channels} // "") ) {
		$sm_c =~ s/^\s+|\s+$//g;
		next if $sm_c eq "";
		next if $sm_c !~ /^[\d\.:\-\*]+$/; # simple OBIS sanity check
		push @sm_channels, $sm_c;
	}
	@sm_channels = ("1-0:1.8.0", "1-0:2.8.0", "1-0:16.7.0") if !@sm_channels;
	$sm_cfg->{channels} = \@sm_channels;

	# Deterministic UUIDs per channel (needed by vzlogger)
	my %sm_uuids;
	foreach my $sm_c (@sm_channels) {
		my $sm_h = md5_hex("loxberry-smartmeter-vzlogger-" . $sm_c);
		$sm_uuids{$sm_c} = join('-', substr($sm_h,0,8), substr($sm_h,8,4), substr($sm_h,12,4), substr($sm_h,16,4), substr($sm_h,20,12));
	}
	$sm_cfg->{uuids} = \%sm_uuids;

	$sm_jsonobj->write();

	&gen_vzlogger_conf($sm_cfg);
	&vz_restart($sm_cfg);

	return();
}

##########################################################################
# Generate vzlogger.conf (JSON) from saved settings
##########################################################################

sub gen_vzlogger_conf
{
	my ($sm_cfg) = @_;
	require JSON::PP;

	my @sm_chans;
	foreach my $sm_c (@{$sm_cfg->{channels}}) {
		push @sm_chans, {
			api        => "null",
			uuid       => $sm_cfg->{uuids}{$sm_c},
			identifier => $sm_c,
		};
	}

	my $sm_meter = {
		enabled  => $sm_cfg->{enabled} ? JSON::PP::true() : JSON::PP::false(),
		protocol => $sm_cfg->{protocol},
		device   => $sm_cfg->{device},
		baudrate => $sm_cfg->{baudrate} + 0,
		parity   => $sm_cfg->{parity},
		channels => \@sm_chans,
	};
	# Ohne diesen Schluessel nimmt vzlogger den Zeitstempel des Zaehlers.
	$sm_meter->{use_local_time} = (defined $sm_cfg->{localtime} && !$sm_cfg->{localtime})
		? JSON::PP::false() : JSON::PP::true();
	# D0 needs a pull sequence to request data ("/?!<CR><LF>")
	$sm_meter->{pullseq} = "2F3F210D0A" if $sm_cfg->{protocol} eq "d0";

	my $sm_conf = {
		verbosity => 5,
		log       => "/dev/shm/$lbpplugindir/vzlogger.log",
		retry     => 30,
		local     => {
			enabled => JSON::PP::true(),
			port    => $sm_cfg->{httpport} + 0,
			index   => JSON::PP::true(),
			timeout => 0,
			buffer  => -1,
		},
		meters => [ $sm_meter ],
	};

	open(my $sm_fh, ">", $sm_vzconffile) or return;
	print $sm_fh JSON::PP->new->pretty->canonical->encode($sm_conf);
	close($sm_fh);

	return();
}



##########################################################################
# Ansicht: MQTT  (einzige Stelle, an der MQTT eingestellt wird)
##########################################################################

# MQTT steht in der Legacy-Konfigurationsdatei, weil beide Betriebsarten
# denselben Weg benutzen. Gelesen wird an mehreren Stellen, geschrieben nur hier.
sub mqtt_lesen
{
	my ($sm_an, $sm_topic) = (0, "smartmeter");
	my $sm_f = "$lbpconfigdir/smartmeter.cfg";
	if (-e $sm_f && open(my $sm_fh, "<", $sm_f)) {
		while (my $sm_z = <$sm_fh>) {
			$sm_an    = 1  if $sm_z =~ /^\s*SENDMQTT\s*=\s*1/;
			$sm_topic = $1 if $sm_z =~ /^\s*MQTTTOPIC\s*=\s*(\S+)/;
		}
		close($sm_fh);
	}
	return ($sm_an, $sm_topic);
}

sub form_mqtt
{
	$sm_template->param("FORM_MQTT", 1);
	my ($sm_an, $sm_topic) = &mqtt_lesen();
	$sm_template->param("MQ_AN",    $sm_an);
	$sm_template->param("MQ_TOPIC", $sm_topic);
	$sm_template->param("MQ_SAVED", $sm_q->{saveformdata} ? 1 : 0);
	return();
}

sub save_mqtt
{
	my $sm_f = "$lbpconfigdir/smartmeter.cfg";
	return() if !-e $sm_f;
	my $sm_c = eval { new Config::Simple($sm_f) };
	return() if !$sm_c;

	$sm_c->param("MAIN.SENDMQTT", ($sm_q->{mq_an} ? 1 : 0));

	# Hausregel: Eingaben nicht hart filtern. Nur Steuerzeichen,
	# Anfuehrungszeichen und Leerraum entfernen - alles andere ist in einem
	# MQTT-Thema erlaubt.
	my $sm_t = $sm_q->{mq_topic} // "";
	$sm_t =~ s/[\x00-\x1f"\x27\s]//g;
	$sm_t =~ s{^/+|/+$}{}g;
	$sm_t = "smartmeter" if $sm_t eq "";
	$sm_c->param("MAIN.MQTTTOPIC", $sm_t);

	$sm_c->save();
	return();
}

##########################################################################
# Ansicht: Einbindung in Loxone
##########################################################################

sub form_loxone
{
	$sm_template->param("FORM_LOXONE", 1);

	my $sm_jsonobj = LoxBerry::JSON->new();
	my $sm_vz = $sm_jsonobj->open(filename => $sm_vzcfgfile, readonly => 1) || {};

	# Legacy-Konfiguration (Themenpraefix, UDP)
	my ($sm_topic, $sm_udp, $sm_read) = ("smartmeter", "", 0);
	my $sm_lcfg = "$lbpconfigdir/smartmeter.cfg";
	if (-e $sm_lcfg && open(my $sm_f, "<", $sm_lcfg)) {
		while (my $sm_z = <$sm_f>) {
			$sm_topic = $1 if $sm_z =~ /^\s*MQTTTOPIC\s*=\s*(\S+)/;
			$sm_udp   = $1 if $sm_z =~ /^\s*UDPPORT\s*=\s*(\d+)/;
			$sm_read  = 1  if $sm_z =~ /^\s*READ\s*=\s*1/;
		}
		close($sm_f);
	}

	# Seriennummern der erkannten Lesekoepfe
	my @sm_ser = map { my $sm_x = $_; $sm_x =~ s{.*/}{}; { SER => $sm_x } } glob("/dev/serial/smartmeter/*");

	$sm_template->param("LX_TOPIC",   $sm_topic);
	$sm_template->param("LX_UDPPORT", $sm_udp || ($sm_vz->{udpport} // 7000));
	$sm_template->param("LX_SERIALS", \@sm_ser);
	$sm_template->param("LX_LEGACY",  $sm_read ? 1 : 0);
	$sm_template->param("LX_VZUDP",   $sm_vz->{sendudp} ? 1 : 0);
	$sm_template->param("LX_HOST",    LoxBerry::System::lbhostname() || "loxberry");
	$sm_template->param("LX_PLUGINDIR", $lbpplugindir);
	return();
}

##########################################################################
# Ansicht: Test
##########################################################################

sub form_test
{
	$sm_template->param("FORM_TEST", 1);
	my $sm_jsonobj = LoxBerry::JSON->new();
	my $sm_vz = $sm_jsonobj->open(filename => $sm_vzcfgfile, readonly => 1) || {};
	$sm_template->param("T_HTTPPORT", $sm_vz->{httpport} // 8083);
	$sm_template->param("LX_HOST", LoxBerry::System::lbhostname() || "loxberry");
	$sm_template->param("LX_PLUGINDIR", $lbpplugindir);
	return();
}

##########################################################################
# Ansicht: Logdateien
##########################################################################

sub form_log
{
	$sm_template->param("FORM_LOG", 1);
	$sm_template->param("VZ_LOG", &vz_logtail());
	return();
}

##########################################################################
# vzlogger helpers
##########################################################################

# Find a runnable vzlogger binary.
# Returns ($path, $reason). $path is "" if nothing is runnable; $reason then
# explains why - that text is shown to the user verbatim.
sub vz_binary
{
	my $sm_arch = `uname -m 2>/dev/null`; chomp $sm_arch;

	my $sm_sys = `command -v vzlogger 2>/dev/null`;
	chomp $sm_sys;

	if (!$sm_sys) {
		return ("", "Es ist kein vzlogger installiert. Der Knopf \"vzlogger installieren\" richtet die "
		          . "Paketquelle von volkszaehler.org ein und installiert das zu dieser Architektur "
		          . "($sm_arch) passende Paket. Bis dahin liefert die Legacy-Betriebsart Werte - "
		          . "die braucht kein vzlogger.");
	}
	if (!-x $sm_sys) {
		return ("", "$sm_sys ist nicht ausfuehrbar (Dateirechte).");
	}
	return ($sm_sys, "") if &vz_binary_laeuft($sm_sys);

	# Vorhanden, aber nicht startbar - der ausfuehrliche Bericht.
	my $sm_check = "$lbpbindir/vz_check.sh";
	my $sm_bericht = "";
	if (-x $sm_check) {
		$sm_bericht = `"$sm_check" "$sm_sys" 2>&1`;
		$sm_bericht = "" if !defined $sm_bericht;
		$sm_bericht =~ s/\s*\n\s*/ | /g;
		$sm_bericht =~ s/\s+/ /g;
	}
	if (!$sm_bericht) {
		my ($sm_out, $sm_rc) = &vz_binary_probe($sm_sys);
		$sm_out =~ s/\s+/ /g;
		$sm_bericht = "startet nicht (rc=$sm_rc): " . substr($sm_out, 0, 160);
	}
	return ("", "$sm_sys ist vorhanden, startet aber nicht. $sm_bericht");
}

# Fassung laut Paketverwaltung
sub vz_paket
{
	my ($sm_was) = @_;
	my $sm_h = "$lbpbindir/vzlogger_pkg.sh";
	return "" if !-x $sm_h;
	my $sm_v = `"$sm_h" $sm_was 2>/dev/null`;
	$sm_v = "" if !defined $sm_v;
	chomp $sm_v;
	return $sm_v;
}

# Installation anstossen (ueber sudoers freigegeben)
sub vz_install
{
	my $sm_h = "$lbpbindir/vzlogger_pkg.sh";
	return "Paket-Helfer fehlt: $sm_h" if !-x $sm_h;
	my $sm_out = `sudo -n "$sm_h" install 2>&1`;
	$sm_out = "" if !defined $sm_out;
	return $sm_out ? $sm_out : "Keine Ausgabe.";
}

# Startversuch. Liefert (Ausgabe, Rueckgabewert).
sub vz_binary_probe
{
	my ($sm_bin) = @_;
	my $sm_out = `"$sm_bin" --version 2>&1`;
	my $sm_rc  = $? >> 8;
	$sm_out = "" if !defined $sm_out;
	return ($sm_out, $sm_rc);
}

# Laeuft das Programm wirklich? Die Fehlermeldung der Shell enthaelt den
# Pfad und damit das Wort "vzlogger" - danach darf also nicht gesucht werden.
sub vz_binary_laeuft
{
	my ($sm_bin) = @_;
	my ($sm_out, $sm_rc) = &vz_binary_probe($sm_bin);
	return 0 if $sm_out =~ /not found|No such file|cannot execute|Exec format error|Permission denied|Syntax error/i;
	return 1 if $sm_rc == 0;
	return 1 if $sm_out =~ /^\s*vzlogger\s+[\d.]/i;
	return 0;
}

# PIDs of OUR vzlogger - matched against our own config file, so a vzlogger
# of another plugin is never mistaken for ours.
sub vz_running
{
	# pgrep -f findet auch die Shell, die pgrep aufruft - ihre Befehlszeile
	# enthaelt das Suchmuster. Deshalb jede Fundstelle gegen den echten
	# Programmnamen pruefen.
	my $sm_raw = `pgrep -f -- "-c $sm_vzconffile" 2>/dev/null`;
	$sm_raw = "" if !defined $sm_raw;
	my @sm_ok;
	foreach my $sm_p (split(/\s+/, $sm_raw)) {
		next if $sm_p !~ /^\d+$/;
		my $sm_c = `ps -p $sm_p -o comm= 2>/dev/null`;
		next if !defined $sm_c;
		chomp $sm_c;
		push @sm_ok, $sm_p if $sm_c =~ /vzlogger/i;
	}
	return join(" ", @sm_ok);
}

# Anything else holding the serial device? Returns a text or "".
sub vz_device_busy
{
	my ($sm_dev) = @_;
	return "" if !$sm_dev;
	my $sm_real = -l $sm_dev ? readlink($sm_dev) : $sm_dev;
	$sm_real = "/dev/" . $sm_real if $sm_real !~ m{^/};
	# fuser ohne -v: PIDs auf der Standardausgabe, Namen auf stderr.
	# Mit -v landete der Geraetename (0047) in der PID-Liste.
	my $sm_ziele = ($sm_real && $sm_real ne $sm_dev) ? "$sm_dev $sm_real" : $sm_dev;
	my $sm_out = `fuser $sm_ziele 2>/dev/null`;
	$sm_out = "" if !defined $sm_out;
	my $sm_mine = &vz_running();
	my %sm_mine = map { $_ => 1 } split(/\s+/, $sm_mine);
	my (%sm_seen, @sm_other);
	foreach my $sm_p ($sm_out =~ /\b(\d+)\b/g) {
		next if $sm_mine{$sm_p} || $sm_seen{$sm_p}++;
		next if !-d "/proc/$sm_p";          # Prozess inzwischen weg
		push @sm_other, $sm_p;
	}
	return "" if !@sm_other;
	my @sm_names;
	foreach my $sm_p (@sm_other) {
		my $sm_c = `ps -p $sm_p -o comm= 2>/dev/null`; chomp $sm_c;
		push @sm_names, ($sm_c ? "$sm_c ($sm_p)" : $sm_p);
	}
	return join(", ", @sm_names);
}

# Is the legacy reader polling? It uses the same serial device.
sub vz_legacy_active
{
	my $sm_f = "$lbpconfigdir/smartmeter.cfg";
	return 0 if !-e $sm_f;
	open(my $sm_fh, "<", $sm_f) or return 0;
	my @sm_l = <$sm_fh>; close($sm_fh);
	foreach my $sm_z (@sm_l) {
		return 1 if $sm_z =~ /^\s*READ\s*=\s*1\s*$/;
	}
	return 0;
}

sub vz_restart
{
	my ($sm_cfg) = @_;
	my $sm_log = "/dev/shm/$lbpplugindir/vzlogger.log";
	system("mkdir -p /dev/shm/$lbpplugindir > /dev/null 2>&1");

	system("pkill -f -- \"-c $sm_vzconffile\" > /dev/null 2>&1");
	sleep 1;
	return("") if !$sm_cfg->{enabled};

	my ($sm_bin, $sm_why) = &vz_binary();
	if (!$sm_bin) {
		&vz_note($sm_log, "START ABGEBROCHEN: $sm_why");
		return($sm_why);
	}

	# Startfehler landen im Protokoll statt in /dev/null.
	system("nohup \"$sm_bin\" -c \"$sm_vzconffile\" >> \"$sm_log\" 2>&1 &");
	sleep 2;
	my $sm_pid = &vz_running();
	if (!$sm_pid) {
		&vz_note($sm_log, "START FEHLGESCHLAGEN: $sm_bin -c $sm_vzconffile lief nach 2 Sekunden nicht mehr. Ursache siehe Zeilen darueber.");
		return("vzlogger wurde gestartet, lief aber nach zwei Sekunden nicht mehr. Einzelheiten stehen im Protokoll unten.");
	}
	&vz_note($sm_log, "gestartet: $sm_bin (PID $sm_pid)");
	return("");
}

sub vz_note
{
	my ($sm_file, $sm_text) = @_;
	my @sm_t = localtime(time);
	my $sm_stamp = sprintf("%04d-%02d-%02d %02d:%02d:%02d", $sm_t[5]+1900, $sm_t[4]+1, $sm_t[3], $sm_t[2], $sm_t[1], $sm_t[0]);
	open(my $sm_fh, ">>", $sm_file) or return;
	print $sm_fh "$sm_stamp [Plugin] $sm_text\n";
	close($sm_fh);
	return;
}

##########################################################################
# Print Form
##########################################################################

sub form_print
{

	# Navbar
	our %sm_navbar;

	$sm_navbar{10}{Name} = "$sm_L{'MENU.LABEL_VZLOGGER'}";
	$sm_navbar{10}{URL} = 'index.cgi';
	$sm_navbar{10}{active} = 1 if $sm_q->{form} eq "vzlogger";

	$sm_navbar{20}{Name} = "$sm_L{'MENU.LABEL_LEGACY'}";
	$sm_navbar{20}{URL} = 'index_legacy.cgi';
	$sm_navbar{20}{active} = 1 if $sm_q->{form} eq "legacy";

	# Template
	LoxBerry::Web::lbheader($sm_L{'COMMON.LABEL_PLUGINTITLE'} . " V$sm_version", "https://www.loxwiki.eu/x/mA-L", "");
	print $sm_template->output();
	LoxBerry::Web::lbfooter();

	exit;

}


######################################################################
# AJAX functions
######################################################################

sub ajax_header
{
	print $sm_cgi->header(
			-type => 'application/json',
			-charset => 'utf-8',
			-status => '200 OK',
	);
	return();
}

##########################################################################
# Diagnose: acht Pruefungen, die zusammen zeigen, wo es klemmt
##########################################################################

sub vz_diagnose
{
	my ($sm_cfg) = @_;
	my @sm_d;
	my %sm_farbe = ( ok => "color:#1a7f1a;", warn => "color:#b06000;", fail => "color:#b00000;" );
	my $sm_add = sub {
		push @sm_d, {
			LABEL       => $_[0],
			STATE       => $_[1],
			TEXT        => $_[2],
			STATE_OK    => ($_[1] eq "ok"   ? 1 : 0),
			STATE_WARN  => ($_[1] eq "warn" ? 1 : 0),
			STATE_FAIL  => ($_[1] eq "fail" ? 1 : 0),
			STATE_COLOR => ($sm_farbe{$_[1]} // ""),
		};
	};

	# 1 - Betriebsart
	if (!$sm_cfg->{enabled}) {
		$sm_add->("Betriebsart", "warn", "vzLogger ist ausgeschaltet. Es wird nichts gelesen.");
		return \@sm_d;
	}
	$sm_add->("Betriebsart", "ok", "vzLogger ist eingeschaltet.");

	# 2 - Programm
	my ($sm_bin, $sm_why) = &vz_binary();
	my $sm_cur = &vz_paket("current");
	my $sm_avl = &vz_paket("available");
	if ($sm_bin) {
		my $sm_v = `"$sm_bin" --version 2>&1 | head -1`; chomp $sm_v;
		my $sm_txt = "$sm_bin" . ($sm_v ? " - $sm_v" : "");
		$sm_txt .= " (Paket $sm_cur" . (($sm_avl && $sm_avl ne $sm_cur) ? ", verfuegbar $sm_avl" : "") . ")" if $sm_cur;
		$sm_add->("Programm", ($sm_avl && $sm_cur && $sm_avl ne $sm_cur) ? "warn" : "ok", $sm_txt);
	} else {
		$sm_add->("Programm", "fail", $sm_why);
	}

	# 3 - Prozess
	my $sm_pid = &vz_running();
	if ($sm_pid) {
		$sm_add->("Prozess", "ok", "laeuft, PID $sm_pid");
	} else {
		$sm_add->("Prozess", "fail", "Es laeuft kein vzlogger mit unserer Konfiguration ($sm_vzconffile).");
	}

	# 4 - Konfigurationsdatei
	if (!-e $sm_vzconffile) {
		$sm_add->("Konfiguration", "fail", "$sm_vzconffile fehlt. Einmal Speichern erzeugt sie neu.");
	} else {
		my $sm_txt = do { local $/; open(my $sm_f, "<", $sm_vzconffile); my $sm_c = <$sm_f>; close($sm_f); $sm_c };
		$sm_txt = "" if !defined $sm_txt;
		my $sm_n = () = $sm_txt =~ /"identifier"/g;
		if ($sm_txt !~ /"meters"/) {
			$sm_add->("Konfiguration", "fail", "In $sm_vzconffile steht kein meters-Abschnitt.");
		} elsif (!$sm_n) {
			$sm_add->("Konfiguration", "fail", "Kein einziger Kanal in der Konfiguration. Ohne Kanal liest vzlogger nichts.");
		} elsif ($sm_txt =~ /"interval"\s*:\s*-/) {
			$sm_add->("Konfiguration", "warn", "$sm_n Kanaele - aber es steht ein negatives interval darin. Bei sendenden SML-Zaehlern gehoert der Schluessel weggelassen.");
		} else {
			$sm_add->("Konfiguration", "ok", "$sm_n Kanaele, kein interval-Schluessel (richtig fuer sendende SML-Zaehler).");
		}
	}

	# 5 - Lesekopf
	my $sm_dev = $sm_cfg->{device} // "";
	if (!$sm_dev) {
		$sm_add->("Lesekopf", "fail", "Kein Geraet ausgewaehlt.");
	} elsif (!-e $sm_dev) {
		$sm_add->("Lesekopf", "fail", "$sm_dev gibt es nicht. Lesekopf abziehen und neu anstecken; die udev-Regel wird beim Start des Plugins angelegt.");
	} elsif (!-r $sm_dev) {
		$sm_add->("Lesekopf", "fail", "$sm_dev ist nicht lesbar (Rechte).");
	} else {
		my $sm_t = -l $sm_dev ? readlink($sm_dev) : "";
		$sm_add->("Lesekopf", "ok", $sm_dev . ($sm_t ? " -> $sm_t" : ""));
	}

	# 6 - Belegung der Schnittstelle (der haeufigste Fallstrick)
	my $sm_busy   = &vz_device_busy($sm_dev);
	my $sm_legacy = &vz_legacy_active();
	if ($sm_legacy) {
		$sm_add->("Schnittstelle belegt", "fail", "Die Legacy-Abfrage ist eingeschaltet und greift auf dasselbe Geraet zu. "
			. "Zwei Leser koennen sich eine serielle Schnittstelle nicht teilen. Entweder Legacy abschalten oder vzLogger.");
	} elsif ($sm_busy) {
		$sm_add->("Schnittstelle belegt", "warn", "Fremder Zugriff auf $sm_dev: $sm_busy");
	} else {
		$sm_add->("Schnittstelle belegt", "ok", "Niemand sonst greift auf $sm_dev zu.");
	}

	# 7 - HTTP-Schnittstelle und Messwerte
	my $sm_port = $sm_cfg->{httpport} // 8083;
	my $sm_raw  = `curl -s -m 5 http://127.0.0.1:$sm_port/ 2>/dev/null`;
	$sm_raw = "" if !defined $sm_raw;
	if ($sm_raw !~ /vzlogger/) {
		$sm_add->("HTTP-Schnittstelle", "fail", "Port $sm_port antwortet nicht. Entweder laeuft vzlogger nicht, oder der Port ist belegt.");
		$sm_add->("Messwerte", "fail", "Keine Werte - ohne HTTP-Schnittstelle kann das Plugin sie nicht abholen.");
	} else {
		my $sm_cnt  = () = $sm_raw =~ /"uuid"/g;
		my @sm_last = $sm_raw =~ /"last"\s*:\s*(\d+)/g;
		my $sm_max  = 0; foreach my $sm_x (@sm_last) { $sm_max = $sm_x if $sm_x > $sm_max; }
		my $sm_gut  = grep { $_ > 0 } @sm_last;
		$sm_add->("HTTP-Schnittstelle", "ok", "Port $sm_port antwortet, $sm_cnt Kanaele angemeldet.");
		if (!$sm_cnt) {
			$sm_add->("Messwerte", "fail", "vzlogger laeuft, kennt aber keine Kanaele. Einmal Speichern und danach den Dienst neu starten.");
		} elsif (!$sm_gut) {
			my $sm_lz = (defined $sm_cfg->{localtime} && !$sm_cfg->{localtime}) ? 1 : 0;
			my $sm_zeit = "";
			if (-e "/dev/shm/$lbpplugindir/vzlogger.log") {
				my $sm_t = `tail -n 60 "/dev/shm/$lbpplugindir/vzlogger.log" 2>/dev/null`;
				$sm_zeit = 1 if defined $sm_t && $sm_t =~ /timestamp before 1990/;
			}
			if ($sm_zeit) {
				$sm_add->("Messwerte", "fail", "Der Zaehler wird gelesen, aber vzlogger verwirft jedes Telegramm: "
					. "\"timestamp before 1990, IGNORING\". Dieser Zaehler sendet keine gestellte Uhr. "
					. "Abhilfe: \"Zeitstempel\" auf \"Rechner-Uhrzeit\" stellen und speichern"
					. ($sm_lz ? " - die Einstellung steht derzeit auf der Uhr des Zaehlers." : "."));
			} else {
				$sm_add->("Messwerte", "fail", "Alle $sm_cnt Kanaele stehen auf last=0 - es ist noch kein einziges Telegramm angekommen. "
					. "Pruefe Sitz des Lesekopfs, Baudrate und Protokoll. Das Protokoll unten zeigt, was vzlogger meldet.");
			}
		} else {
			my $sm_age = $sm_max > 1000000000000 ? int(time - $sm_max/1000) : int(time - $sm_max);
			$sm_add->("Messwerte", ($sm_age > 300 ? "warn" : "ok"),
				"$sm_gut von $sm_cnt Kanaelen liefern Werte, letzter Empfang vor $sm_age Sekunden.");
		}
	}

	return \@sm_d;
}

# Letzte Zeilen der beiden Protokolle - ohne Umweg ueber den Log Manager
sub vz_logtail
{
	my @sm_out;
	foreach my $sm_f ("/dev/shm/$lbpplugindir/vzlogger.log", "/dev/shm/$lbpplugindir/vzlogger_fetch.log") {
		next if !-e $sm_f;
		my $sm_t = `tail -n 25 "$sm_f" 2>/dev/null`;
		next if !$sm_t;
		push @sm_out, "--- $sm_f ---\n$sm_t";
	}
	return @sm_out ? join("\n", @sm_out) : "Noch keine Protokolleintraege vorhanden.";
}
