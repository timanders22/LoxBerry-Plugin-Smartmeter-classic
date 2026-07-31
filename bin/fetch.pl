#!/usr/bin/perl

# Copyright 2017 Michael Schlenstedt, michael@loxberry.de
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

use Config::Simple;
use File::HomeDir;
use Cwd 'abs_path';
use IO::Socket; # For sending UDP packages
use Getopt::Long;
use LoxBerry::System;
#use warnings;
#use strict;
no strict "refs"; # we need it for template system and for contructs like ${"skalar".$i} in loops
no strict "subs"; # we need it for template system and for contructs like ${"skalar".$i} in loops

##########################################################################
# Variables
##########################################################################
my  $cfg;
my  $plugin_cfg;
my  %plugin_cfg_hash;
my  $installfolder;
my  $version;
my  $home = $lbhomedir;
my  $psubfolder = $lbpplugindir;
my  $pname;
my  @heads;
my  $name;
my  $serial;
my  $device;
my  $meter;
my  $protocol;
my  $startbaudrate;
my  $baudrate;
my  $timeout;
my  $handshake;
my  $databits;
my  $stopbits;
my  $parity;
my  $delay;
our $miniservers;
our $clouddns;
our $udpport;
our $sendudp;
my  $udpstring;
my  @lines;
my  $i;
my  $verbose;
my  $force;

##########################################################################
# Read Settings
##########################################################################

# Version of this script
$version = "0.2";

# Figure out in which subfolder we are installed
#$psubfolder = abs_path($0);
#$psubfolder =~ s/(.*)\/(.*)\/bin\/(.*)$/$2/g;

# If Cron is minimum, here is the rerun mark
RERUN:

# Read general config
$cfg	 	= new Config::Simple("$home/config/system/general.cfg") or die $cfg->error();
$installfolder	= $cfg->param("BASE.INSTALLFOLDER");
$miniservers	= $cfg->param("BASE.MINISERVERS");
$clouddns	= $cfg->param("BASE.CLOUDDNS");

# Read plugin config
$plugin_cfg 	= new Config::Simple("$installfolder/config/plugins/$psubfolder/smartmeter.cfg") or die $plugin_cfg->error();
$pname          = $plugin_cfg->param("MAIN.SCRIPTNAME");
$udpport        = $plugin_cfg->param("MAIN.UDPPORT");
$sendudp        = $plugin_cfg->param("MAIN.SENDUDP");
$sendmqtt       = $plugin_cfg->param("MAIN.SENDMQTT");
$sendmqtt       = 1 if !defined $sendmqtt;
$mqtttopic      = $plugin_cfg->param("MAIN.MQTTTOPIC") || "smartmeter";
$cron		= $plugin_cfg->param("MAIN.CRON");

# Commandline options
GetOptions (    "verbose"          => \$verbose,
                "force"            => \$force,
);

if ($verbose) {
	$verbose = "--verbose";
}

# Create temp folder if not already exist
if (!-d "/dev/shm/$psubfolder") {
	system("mkdir -p /dev/shm/$psubfolder > /dev/null 2>&1");
}
# Check for temporary log folder
if (!-e "$installfolder/log/plugins/$psubfolder/shm") {
	system("ln -s /dev/shm/$psubfolder  $installfolder/log/plugins/$psubfolder/shm > /dev/null 2>&1");
}

# Delete old Logfile
if (-e "/dev/shm/$psubfolder/fetch.log") {
    system("rm /dev/shm/$psubfolder/fetch.log > /dev/null 2>&1");
}

# Check if we should read automatically
if ( !$plugin_cfg->param("MAIN.READ") && !$force ) {
	&LOG ("Reading serial devices is currently deactivated. Giving up.", "FAIL");
	exit;
}

# Detect which IR Heads are connected / configured
Config::Simple->import_from("$installfolder/config/plugins/$psubfolder/smartmeter.cfg", \%plugin_config_hash);
while (my ($configname, $configvalue) = each %plugin_config_hash){
	if ( $configname =~ /SERIAL/ ) {
		$name 		=	$plugin_cfg->param("$configvalue.NAME");
		$serial		=	$plugin_cfg->param("$configvalue.SERIAL");
		$device		=	$plugin_cfg->param("$configvalue.DEVICE");
		$meter		=	$plugin_cfg->param("$configvalue.METER");
		$protocol	=	$plugin_cfg->param("$configvalue.PROTOCOL");
		$startbaudrate	=	$plugin_cfg->param("$configvalue.STARTBAUDRATE");
		$baudrate	=	$plugin_cfg->param("$configvalue.BAUDRATE");
		$timeout	=	$plugin_cfg->param("$configvalue.TIMEOUT");
		$handshake	=	$plugin_cfg->param("$configvalue.HANDSHAKE");
		$databits	=	$plugin_cfg->param("$configvalue.DATABITS");
		$stopbits	=	$plugin_cfg->param("$configvalue.STOPBITS");
		$parity		=	$plugin_cfg->param("$configvalue.PARITY");
		$delay 		=	$plugin_cfg->param("$configvalue.DELAY");
		$crc 		=	$plugin_cfg->param("$configvalue.CRC");
		&LOG ("$serial: Found configuration for $name", "INFO");

		# Check if head is connected and config is complete
		if ( !-e $plugin_cfg->param("$configvalue.DEVICE") ) {
			&LOG ("$serial: Device does not exist. Skipping.", "INFO");
			next;
		}
		if ( $plugin_cfg->param("$configvalue.METER") eq "0" ) {
			&LOG ("$serial: Configuration for $name is not complete. Skipping.", "INFO");
			next;
		}

		# If set to manual, use manual settings
		if ( $meter eq "manual" ) {
			&LOG ("$serial: Manual settings.", "INFO");
			&LOG ("$serial: Protocol: $protocol", "INFO");
			&LOG ("$serial: Timeout: $timeout", "INFO");
			&LOG ("$serial: Delay: $delay", "INFO");
			&LOG ("$serial: CRC: $crc", "INFO");
			&LOG ("$serial: Device: $device", "INFO");
			&LOG ("$serial: Baudrate:$baudrate/$startbaudrate Databits:$databits Stopbits:$stopbits Parity:$parity Handshake:$handshake", "INFO");
			system("$installfolder/bin/plugins/$psubfolder/sm_logger.pl --device $device --protocol $protocol --startbaudrate $startbaudrate --baudrate $baudrate --timeout $timeout --delay $delay --handshake $handshake --databits $databits --stopbits $stopbits --parity $parity --crc $crc $verbose");
            #system("$installfolder/bin/plugins/$psubfolder/sm_logger.pl --device $device --parse 015A98CA --protocol $protocol --startbaudrate $startbaudrate --baudrate $baudrate --timeout $timeout --delay $delay --handshake $handshake --databits $databits --stopbits $stopbits --parity $parity --crc $crc $verbose");
        } else {
			# If set to  a meter, use standard settings for this meter
			&LOG ("$serial: Presetting: $meter.", "INFO");
			system("$installfolder/bin/plugins/$psubfolder/sm_logger.pl --device $device --protocol $meter $verbose");
			#system("$installfolder/bin/plugins/$psubfolder/sm_logger.pl --device $device --parse 01304DD6 --protocol $meter $verbose");
            }

		# Send data by UDP to all configured miniservers
		# If we should send by UDP, figure out which Miniservers are configured
		if ($sendudp) {

		  $udpstring = "";

		  # Read Data file
		  if ( !-e "/dev/shm/$psubfolder/$serial\.data" ) {
			$udpstring = "$serial: No data found";
 		  } else {
			open(F,"</dev/shm/$psubfolder/$serial\.data");
				@lines = <F>;
			close(F);

			foreach ( @lines ) {
			  chomp ($_);
			  $udpstring .= "$_; ";
			}
		  }

		  &LOG("$serial: UDP String to send: $udpstring", "INFO");

		  # LB4 fix: resolve Miniservers via LoxBerry::System::get_miniservers()
		  # (handles CloudDNS internally). The old code called the no longer
		  # existing script webfrontend/cgi/system/tools/showclouddns.pl.
		  my %ms = LoxBerry::System::get_miniservers();

		  # Send Data
		  foreach my $msno (sort keys %ms) {

		    if ( !$ms{$msno}{IPAddress} ) {
		      &LOG("$serial: Could not find IP Address for Miniserver " . $ms{$msno}{Name} . ".", "WARN");
		      next;
		    }

		    # Send value
		    my $sock = IO::Socket::INET->new(
		      Proto    => 'udp',
		      PeerPort => $udpport,
		      PeerAddr => $ms{$msno}{IPAddress},
		    ) or die "<ERROR> Could not create socket: $!\n";
		    $sock->send($udpstring) or die "Send error: $!\n";
		    &LOG("$serial: Send OK to " . $ms{$msno}{Name} . ". IP:" . $ms{$msno}{IPAddress} . " Port:$udpport", "OK");
		  }

		}

		# Werte per MQTT veroeffentlichen (Hausstandard)
		if ($sendmqtt) {

		  my @paare;
		  if ( -e "/dev/shm/$psubfolder/$serial\.data" ) {
		    open(F,"</dev/shm/$psubfolder/$serial\.data");
		      my @mlines = <F>;
		    close(F);
		    foreach my $z ( @mlines ) {
		      chomp($z);
		      next if $z =~ /^#/;
		      # Zeilenform:  SERIAL:Schluessel:Wert
		      if ( $z =~ /^\Q$serial\E:([^:]+):(.*)$/ ) {
		        push @paare, [ $1, $2 ];
		      }
		    }
		  }
		  &SEND_MQTT("$mqtttopic/$serial", \@paare);

		}

	}

}
if ($plugin_cfg->param("MAIN.CRON") eq "M" && !$force) {
	&LOG("$serial: Cronjob is MINIMUM - RERUN", "OK");	
	goto RERUN;
	}
exit;

################################
### SUB: Log
################################

sub LOG
{

        my $message     = shift; # http://wiki.selfhtml.org/wiki/Perl/Subroutinen
        my $type        = uc shift; # http://wiki.selfhtml.org/wiki/Perl/Subroutinen
        if ( !$type ) { $type = "INFO" };

        print "$message\n";

        # Today's date for LOGfile
        (my $sec,my $min,my $hour,my $mday,my $mon,my $year,my $wday,my $yday,my $isdst) = localtime();
        $year = $year+1900;
        $mon = $mon+1;
        $mon = sprintf("%02d", $mon);
        $mday = sprintf("%02d", $mday);
        $hour = sprintf("%02d", $hour);
        $min = sprintf("%02d", $min);
        $sec = sprintf("%02d", $sec);

        # Logfile
        open(F,">>/dev/shm/$psubfolder/fetch.log");
                print F "$year-$mon-$mday $hour:$min:$sec <$type> $message\n";
        close (F);

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
