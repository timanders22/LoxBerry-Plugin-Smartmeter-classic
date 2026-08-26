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

################################
### Moduls
################################
use Device::SerialPort;
use Getopt::Long;
use LoxBerry::System;
use Time::HiRes qw(sleep); # Needed for fractional sleep (e.g. sleep 0.5)
#use File::HomeDir;
#use Cwd 'abs_path';
use DateTime;
# DateTime::TimeZone wird weiter unten benutzt (DateTime::TimeZone->new).
# Das "use" war auskommentiert; es ging bisher nur gut, weil DateTime das
# Modul selbst nachlaedt. Wer sich darauf verlaesst, merkt den Bruch erst,
# wenn DateTime das eines Tages nicht mehr tut.
use DateTime::TimeZone;
#use warnings;
#use strict;

################################
### Configuration
################################

### Defaults
# Die drei Namen der Datendatei stehen auf DATEIEBENE, nicht in
# DATA_OEFFNEN: ein "our" im Rumpf einer Funktion gilt lexikalisch nur
# dort, und die beiden anderen Funktionen sehen die Deklaration dann
# nicht. Ohne "use strict" faellt das nicht auf - der Pruefstand
# Pruefung-Smartmeter-classic-2.3.14/logger_zeitstempel.pl hat es beim
# ersten Lauf gemeldet, weil er selbst streng uebersetzt.
our ($sm_datendatei, $sm_datentmp, $sm_gemessen);
our $verbose = 0;
our $device = "";
our $serial = "";
our $protocol = "";

GetOptions (    "verbose"          => \$verbose,
                "device=s"         => \$device,
                "protocol=s"       => \$protocol,
                "parse=s"          => \$parse,
                "handshake=s"      => \$handshake,
                "baudrate=i"       => \$baudrate,
                "startbaudrate=i"  => \$startbaudrate,
                "timeout=i"        => \$timeout,
                "delay=i"          => \$delay,
                "databits=i"       => \$databits,
                "stopbits=i"       => \$stopbits,
                "parity=s"         => \$parity,
                "crc=s"            => \$crc,
                "help"             => \$help,
);

### Usage
if ( $help ) {
	print "Usage: $0 --device TTYDEVICE [--protocol PROTOCOL] [--startbaudrate STARTBAUDRATE]\n";
	print "       [--baudrate BAUDRATE] [--timeout TIMEOUT] [--delay DELAY} [--handshake HANDSHAKE] [--databits DATABITS]\n";
	print "       [--stopbits STOPBITS] [--parity PARITY] [--crc CRC] [--help] [--verbose] [--help] [--verbose] [--parse DUMPFILE]\n";
	exit;
}

### Debugging?
if ( !$verbose ) {
	$verbose = 0;
} else {
	$verbose = 1;
}

### Serieller Port
if ( !$device && !$parse ) {
	print "Please use --device to specify TTY device. Use --help to get help.\n";
	exit;
}
if ( $device !~ /\/dev\/serial\/smartmeter/ && !$parse ) {
	print "Only devices from /dev/serial/smartmeter/* are supported.\n";
	exit;
}
if ( !-e $device && !$parse ) {
	print "The device $device does not exist.\n";
	exit;
}

### Serial of I/R Head
if ( !$parse ) {
	$serial = $device;
	$serial	=~ s/([\n])//g;
	$serial =~ s%/dev/serial/smartmeter/%%g;
} else {
	$serial	= $parse;
	$serial	=~ s/([\n])//g;
	$serial	=~ s%\.dump%%g;
}

### Figure out in which subfolder we are installed
our $home = $lbhomedir;
our $psubfolder = $lbpplugindir;

# Create temp folder if not already exist
if (!-d "/dev/shm/$psubfolder") {
	system("mkdir -p /dev/shm/$psubfolder > /dev/null 2>&1");
}
# Check for temporary log folder
# Fixed: $installfolder was never defined in this script - use $lbhomedir from LoxBerry::System
if (!-e "$lbhomedir/log/plugins/$psubfolder/shm") {
	system("ln -s /dev/shm/$psubfolder  $lbhomedir/log/plugins/$psubfolder/shm > /dev/null 2>&1");
}

# Clear Log
system("rm /dev/shm/$psubfolder/$serial\.log > /dev/null 2>&1");
if ( !$parse ) {
	system("rm /dev/shm/$psubfolder/$serial\.dump > /dev/null 2>&1");
}

################################
### Determine which protocol to use
################################

if ( $protocol eq "genericd0" ) {

	### Defaults
	our $baudrate = 300 if !$baudrate;
	our $startbaudrate = 300 if !$startbaudrate;
	our $databits = 7 if !$databits;
	our $stopbits = 1 if !$stopbits;
	our $parity = "even" if !$parity;
	our $handshake = "none" if !$handshake;
	our $timeout = "120" if !$timeout;
	our $delay = "2" if !$delay;
	our $preinitcommand = "";
	our $precommand = "";
	our $postcommand = "";

	&PROTO_GENERICD0;
}

elsif ( $protocol eq "genericsml" ) {

	### Defaults
	our $baudrate = 300 if !$baudrate;
	our $startbaudrate = 300 if !$startbaudrate;
	our $databits = 7 if !$databits;
	our $stopbits = 1 if !$stopbits;
	our $parity = "even" if !$parity;
	our $handshake = "none" if !$handshake;
	our $timeout = "120" if !$timeout;
	our $delay = "2" if !$delay;
	our $crc = "CRC16_X_25" if !$crc;
	our $preinitcommand = "";
	our $precommand = "";
	our $postcommand = "";

	&PROTO_GENERICSML;
}

elsif ( $protocol eq "emhed300sml" ) {

	### Defaults
	our $baudrate = 9600 if !$baudrate;
	our $startbaudrate = 9600 if !$startbaudrate;
	our $databits = 8 if !$databits;
	our $stopbits = 1 if !$stopbits;
	our $parity = "none" if !$parity;
	our $handshake = "none" if !$handshake;
	our $timeout = "20" if !$timeout;
	our $delay = "1" if !$delay;
	our $crc = "CRC16_X_25" if !$crc;
	our $preinitcommand = "";
	our $precommand = "";
	our $postcommand = "";

	&PROTO_GENERICSML;
}

elsif ( $protocol eq "emhehzksml" ) {

	### Defaults
	our $baudrate = 9600 if !$baudrate;
	our $startbaudrate = 9600 if !$startbaudrate;
	our $databits = 8 if !$databits;
	our $stopbits = 1 if !$stopbits;
	our $parity = "none" if !$parity;
	our $handshake = "none" if !$handshake;
	our $timeout = "30" if !$timeout;
	our $delay = "30" if !$delay;
	our $crc = "CRC16_X_25" if !$crc;
	our $preinitcommand = "";
	our $precommand = "";
	our $postcommand = "";

	&PROTO_GENERICSML;
}

elsif ( $protocol eq "iskra173d0" ) {

	### Defaults
	our $baudrate = 9600 if !$baudrate;
	our $startbaudrate = 300 if !$startbaudrate;
	our $databits = 7 if !$databits;
	our $stopbits = 1 if !$stopbits;
	our $parity = "even" if !$parity;
	our $handshake = "none" if !$handshake;
	our $timeout = "10" if !$timeout;
	our $delay = "2" if !$delay;
	our $preinitcommand = "";
	our $precommand = "";
	our $postcommand = "";

	&PROTO_GENERICD0;
}

elsif ( $protocol eq "iskra173sml" ) {

	### Defaults
	our $baudrate = 9600 if !$baudrate;
	our $startbaudrate = 300 if !$startbaudrate;
	our $databits = 7 if !$databits;
	our $stopbits = 1 if !$stopbits;
	our $parity = "even" if !$parity;
	our $handshake = "none" if !$handshake;
	our $timeout = "10" if !$timeout;
	our $delay = "2" if !$delay;
	our $crc = "CRC16_X_25" if !$crc;
	our $preinitcommand = "";
	our $precommand = "";
	our $postcommand = "";

	&PROTO_GENERICSML;
}

elsif ( $protocol eq "iskra174d0" ) {

	### Defaults
	our $baudrate = 9600 if !$baudrate;
	our $startbaudrate = 300 if !$startbaudrate;
	our $databits = 7 if !$databits;
	our $stopbits = 1 if !$stopbits;
	our $parity = "even" if !$parity;
	our $handshake = "none" if !$handshake;
	our $timeout = "10" if !$timeout;
	our $delay = "2" if !$delay;
	our $preinitcommand = "";
	our $precommand = "";
	our $postcommand = "";

	&PROTO_GENERICD0;
}

elsif ( $protocol eq "iskra174sml" ) {

	### Defaults
	our $baudrate = 9600 if !$baudrate;
	our $startbaudrate = 300 if !$startbaudrate;
	our $databits = 7 if !$databits;
	our $stopbits = 1 if !$stopbits;
	our $parity = "even" if !$parity;
	our $handshake = "none" if !$handshake;
	our $timeout = "10" if !$timeout;
	our $delay = "2" if !$delay;
	our $crc = "CRC16_X_25" if !$crc;
	our $preinitcommand = "";
	our $precommand = "";
	our $postcommand = "";

	&PROTO_GENERICSML;
}

elsif ( $protocol eq "iskra175d0" ) {

	### Defaults
	our $baudrate = 9600 if !$baudrate;
	our $startbaudrate = 9600 if !$startbaudrate;
	our $databits = 8 if !$databits;
	our $stopbits = 1 if !$stopbits;
	our $parity = "none" if !$parity;
	our $handshake = "none" if !$handshake;
	our $timeout = "60" if !$timeout;
	our $delay = "2" if !$delay;
	our $preinitcommand = "";
	our $precommand = "";
	our $postcommand = "";

	&PROTO_GENERICD0;
}

elsif ( $protocol eq "iskra175sml" ) {

	### Defaults
	our $baudrate = 9600 if !$baudrate;
	our $startbaudrate = 9600 if !$startbaudrate;
	our $databits = 8 if !$databits;
	our $stopbits = 1 if !$stopbits;
	our $parity = "none" if !$parity;
	our $handshake = "none" if !$handshake;
	our $timeout = "60" if !$timeout;
	our $delay = "2" if !$delay;
	our $crc = "CRC16_X_25" if !$crc;
	our $preinitcommand = "";
	our $precommand = "";
	our $postcommand = "";

	&PROTO_GENERICSML;
}

elsif ( $protocol eq "iskra382d0" ) {

	### Defaults
	our $baudrate = 9600 if !$baudrate;
	our $startbaudrate = 9600 if !$startbaudrate;
	our $databits = 7 if !$databits;
	our $stopbits = 1 if !$stopbits;
	our $parity = "even" if !$parity;
	our $handshake = "none" if !$handshake;
	our $timeout = "10" if !$timeout;
	our $delay = "2" if !$delay;
	our $preinitcommand = "";
	our $precommand = "";
	our $postcommand = "";

	&PROTO_GENERICD0;
}

elsif ( $protocol eq "iskra681sml" ) {

	### Defaults
	our $baudrate = 9600 if !$baudrate;
	our $startbaudrate = 9600 if !$startbaudrate;
	our $databits = 8 if !$databits;
	our $stopbits = 1 if !$stopbits;
	our $parity = "none" if !$parity;
	our $handshake = "none" if !$handshake;
	our $timeout = "60" if !$timeout;
	our $delay = "2" if !$delay;
	our $crc = "CRC16_X_25" if !$crc;
	our $preinitcommand = "";
	our $precommand = "";
	our $postcommand = "";

	&PROTO_GENERICSML;
}

elsif ( $protocol eq "iskra691sml" ) {

	### Defaults
	our $baudrate = 9600 if !$baudrate;
	our $startbaudrate = 9600 if !$startbaudrate;
	our $databits = 8 if !$databits;
	our $stopbits = 1 if !$stopbits;
	our $parity = "none" if !$parity;
	our $handshake = "none" if !$handshake;
	our $timeout = "5" if !$timeout;
	our $delay = "1" if !$delay;
	our $crc = "CRC16_X_25" if !$crc;
	our $preinitcommand = "";
	our $precommand = "";
	our $postcommand = "";

	&PROTO_GENERICSML;
}

elsif ( $protocol eq "itronace3000type260d0" ) {

	### Defaults
	our $baudrate = 300 if !$baudrate;
	our $startbaudrate = 300 if !$startbaudrate;
	our $databits = 7 if !$databits;
	our $stopbits = 1 if !$stopbits;
	our $parity = "even" if !$parity;
	our $handshake = "none" if !$handshake;
	our $timeout = "10" if !$timeout;
	our $delay = "4" if !$delay;
	our $preinitcommand = "";
	our $precommand = "";
	our $postcommand = "";

	&PROTO_GENERICD0;
}

elsif ( $protocol eq "landisgyre220sml" ) {

	### Defaults
	our $baudrate = 9600 if !$baudrate;
	our $startbaudrate = 9600 if !$startbaudrate;
	our $databits = 8 if !$databits;
	our $stopbits = 1 if !$stopbits;
	our $parity = "none" if !$parity;
	our $handshake = "none" if !$handshake;
	our $timeout = "120" if !$timeout;
	our $delay = "2" if !$delay;
	our $crc = "CRC16_X_25" if !$crc;
	our $preinitcommand = "";
	our $precommand = "";
	our $postcommand = "";

	&PROTO_GENERICSML;
}

elsif ( $protocol eq "landisgyre320d0" ) {

	### Defaults
	our $baudrate = 4800 if !$baudrate;
	our $startbaudrate = 300 if !$startbaudrate;
	our $databits = 7 if !$databits;
	our $stopbits = 1 if !$stopbits;
	our $parity = "even" if !$parity;
	our $handshake = "none" if !$handshake;
	our $timeout = "20" if !$timeout;
	our $delay = "4" if !$delay;
	our $preinitcommand = "";
	our $precommand = "";
	our $postcommand = "";

	&PROTO_GENERICD0;
}

elsif ( $protocol eq "landisgyre350d0" ) {

	### Defaults
	our $baudrate = 4800 if !$baudrate;
	our $startbaudrate = 300 if !$startbaudrate;
	our $databits = 7 if !$databits;
	our $stopbits = 1 if !$stopbits;
	our $parity = "even" if !$parity;
	our $handshake = "none" if !$handshake;
	our $timeout = "20" if !$timeout;
	our $delay = "4" if !$delay;
	our $preinitcommand = "";
	our $precommand = "";
	our $postcommand = "";

	&PROTO_GENERICD0;
}

elsif ( $protocol eq "pafal20ec3grd0" ) {

	### Defaults
	our $baudrate = 9600 if !$baudrate;
	our $startbaudrate = 300 if !$startbaudrate;
	our $databits = 7 if !$databits;
	our $stopbits = 1 if !$stopbits;
	our $parity = "even" if !$parity;
	our $handshake = "none" if !$handshake;
	our $timeout = "5" if !$timeout;
	our $delay = "2" if !$delay;
	our $preinitcommand = "";
	our $precommand = "";
	our $postcommand = "";

	&PROTO_GENERICD0;
}

elsif ( $protocol eq "siemenstd3511d0" ) {

	### Defaults
	our $baudrate = 9600 if !$baudrate;
	our $startbaudrate = 300 if !$startbaudrate;
	our $databits = 7 if !$databits;
	our $stopbits = 1 if !$stopbits;
	our $parity = "even" if !$parity;
	our $handshake = "none" if !$handshake;
	our $timeout = "10" if !$timeout;
	our $delay = "2" if !$delay;
	our $preinitcommand = "";
	our $precommand = "303531";
	our $postcommand = "";

	&PROTO_GENERICD0;
}

elsif ( $protocol eq "landisgyret550d0" || $protocol eq "siemensuh50do" ) {

	### Defaults
	our $baudrate = 2400 if !$baudrate;
	our $startbaudrate = 300 if !$startbaudrate;
	our $databits = 7 if !$databits;
	our $stopbits = 1 if !$stopbits;
	our $parity = "even" if !$parity;
	our $handshake = "none" if !$handshake;
	our $timeout = "10" if !$timeout;
	our $delay = "1" if !$delay;
	our $preinitcommand = "0000000000000000000000000000000000000000";
	our $precommand = "";
	our $postcommand = "";

	&PROTO_GENERICD0("HEAT");
}

elsif ( $protocol eq "sagemcomt211d0" ) {

	### Defaults
	our $baudrate = 115200 if !$baudrate;
	our $startbaudrate = 115200 if !$startbaudrate;
	our $databits = 8 if !$databits;
	our $stopbits = 1 if !$stopbits;
	our $parity = "none" if !$parity;
	our $handshake = "none" if !$handshake;
	our $timeout = "10" if !$timeout;
	our $delay = "2" if !$delay;
	our $preinitcommand = "";
	our $precommand = "";
	our $postcommand = "";

	&PROTO_GENERICD0;
}

elsif ( $protocol eq "sagemcomt211d0f" ) {

	### Defaults
	our $baudrate = 115200 if !$baudrate;
	our $startbaudrate = 115200 if !$startbaudrate;
	our $databits = 8 if !$databits;
	our $stopbits = 1 if !$stopbits;
	our $parity = "none" if !$parity;
	our $handshake = "none" if !$handshake;
	our $timeout = "10" if !$timeout;
	our $delay = "2" if !$delay;
	our $preinitcommand = "";
	our $precommand = "";
	our $postcommand = "";

	&PROTO_GENERICD0("FLANDERS");
}

elsif ( $protocol eq "apatornorax3dsml" ) {

	### Defaults
	our $baudrate = 9600 if !$baudrate;
	our $startbaudrate = 9600 if !$startbaudrate;
	our $databits = 8 if !$databits;
	our $stopbits = 1 if !$stopbits;
	our $parity = "none" if !$parity;
	our $handshake = "none" if !$handshake;
	our $timeout = "5" if !$timeout;
	our $delay = "1" if !$delay;
	our $crc = "CRC16_X_25" if !$crc;
	our $preinitcommand = "";
	our $precommand = "";
	our $postcommand = "";

	&PROTO_GENERICSML;
}

elsif ( $protocol eq "apatorpicusehz060sml" ) {

	### Defaults
	our $baudrate = 9600 if !$baudrate;
	our $startbaudrate = 9600 if !$startbaudrate;
	our $databits = 8 if !$databits;
	our $stopbits = 1 if !$stopbits;
	our $parity = "none" if !$parity;
	our $handshake = "none" if !$handshake;
	our $timeout = "5" if !$timeout;
	our $delay = "0" if !$delay;
	our $crc = "CRC16_X_25" if !$crc;
	our $preinitcommand = "";
	our $precommand = "";
	our $postcommand = "";

	&PROTO_GENERICSML;
}

elsif ( $protocol eq "bauerbsmqd36ad0" ) {

	### Defaults
	our $baudrate = 300 if !$baudrate;
	our $startbaudrate = 300 if !$startbaudrate;
	our $databits = 7 if !$databits;
	our $stopbits = 1 if !$stopbits;
	our $parity = "even" if !$parity;
	our $handshake = "none" if !$handshake;
	our $timeout = "30" if !$timeout;
	our $delay = "2" if !$delay;
	our $preinitcommand = "";
	our $precommand = "";
	our $postcommand = "";

	&PROTO_GENERICD0;
}

elsif ( $protocol eq "dzgdvs7410sml" ) {

	### Defaults
	our $baudrate = 9600 if !$baudrate;
	our $startbaudrate = 9600 if !$startbaudrate;
	our $databits = 8 if !$databits;
	our $stopbits = 1 if !$stopbits;
	our $parity = "none" if !$parity;
	our $handshake = "none" if !$handshake;
	our $timeout = "60" if !$timeout;
	our $delay = "2" if !$delay;
	our $crc = "CRC16_X_25" if !$crc;
	our $preinitcommand = "";
	our $precommand = "";
	our $postcommand = "";

	&PROTO_GENERICSML;
}

elsif ( $protocol eq "dzgdvs7420sml" ) {

	### Defaults
	our $baudrate = 9600 if !$baudrate;
	our $startbaudrate = 9600 if !$startbaudrate;
	our $databits = 8 if !$databits;
	our $stopbits = 1 if !$stopbits;
	our $parity = "none" if !$parity;
	our $handshake = "none" if !$handshake;
	our $timeout = "30" if !$timeout;
	our $delay = "2" if !$delay;
	our $crc = "CRC16_X_25" if !$crc;
	our $preinitcommand = "";
	our $precommand = "";
	our $postcommand = "";

	&PROTO_GENERICSML;
}

elsif ( $protocol eq "easymeteresy5q3dad0" ) {

	### Defaults
	our $baudrate = 9600 if !$baudrate;
	our $startbaudrate = 9600 if !$startbaudrate;
	our $databits = 7 if !$databits;
	our $stopbits = 1 if !$stopbits;
	our $parity = "even" if !$parity;
	our $handshake = "none" if !$handshake;
	our $timeout = "5" if !$timeout;
	our $delay = "2" if !$delay;
	our $preinitcommand = "";
	our $precommand = "";
	our $postcommand = "";

	&PROTO_GENERICD0;
}

elsif ( $protocol eq "easymeterq3asml" ) {

	### Defaults
	our $baudrate = 9600 if !$baudrate;
	our $startbaudrate = 9600 if !$startbaudrate;
	our $databits = 8 if !$databits;
	our $stopbits = 1 if !$stopbits;
	our $parity = "none" if !$parity;
	our $handshake = "none" if !$handshake;
	our $timeout = "5" if !$timeout;
	our $delay = "1" if !$delay;
	our $crc = "CRC16_X_25" if !$crc;
	our $preinitcommand = "";
	our $precommand = "";
	our $postcommand = "";

	&PROTO_GENERICSML;
}

elsif ( $protocol eq "efrsgmc2sml" ) {

	### Defaults
	our $baudrate = 9600 if !$baudrate;
	our $startbaudrate = 9600 if !$startbaudrate;
	our $databits = 8 if !$databits;
	our $stopbits = 1 if !$stopbits;
	our $parity = "none" if !$parity;
	our $handshake = "none" if !$handshake;
	our $timeout = "5" if !$timeout;
	our $delay = "1" if !$delay;
	our $crc = "CRC16_X_25" if !$crc;
	our $preinitcommand = "";
	our $precommand = "";
	our $postcommand = "";

	&PROTO_GENERICSML;
}

elsif ( $protocol eq "efrsgmc4sml" ) {

	### Defaults
	our $baudrate = 9600 if !$baudrate;
	our $startbaudrate = 9600 if !$startbaudrate;
	our $databits = 8 if !$databits;
	our $stopbits = 1 if !$stopbits;
	our $parity = "none" if !$parity;
	our $handshake = "none" if !$handshake;
	our $timeout = "5" if !$timeout;
	our $delay = "1" if !$delay;
	our $crc = "CRC16_X_25" if !$crc;
	our $preinitcommand = "";
	our $precommand = "";
	our $postcommand = "";

	&PROTO_GENERICSML;
}

elsif ( $protocol eq "efrsgmddsml" ) {

	### Defaults
	our $baudrate = 9600 if !$baudrate;
	our $startbaudrate = 9600 if !$startbaudrate;
	our $databits = 8 if !$databits;
	our $stopbits = 1 if !$stopbits;
	our $parity = "none" if !$parity;
	our $handshake = "none" if !$handshake;
	our $timeout = "30" if !$timeout;
	our $delay = "1" if !$delay;
	our $crc = "CRC16_X_25" if !$crc;
	our $preinitcommand = "";
	our $precommand = "";
	our $postcommand = "";

	&PROTO_GENERICSML;
}

elsif ( $protocol eq "elsteras3000d0" ) {

	### Defaults
	our $baudrate = 9600 if !$baudrate;
	our $startbaudrate = 300 if !$startbaudrate;
	our $databits = 7 if !$databits;
	our $stopbits = 1 if !$stopbits;
	our $parity = "even" if !$parity;
	our $handshake = "none" if !$handshake;
	our $timeout = "13" if !$timeout;
	our $delay = "2" if !$delay;
	our $preinitcommand = "";
	our $precommand = "";
	our $postcommand = "";

	&PROTO_GENERICD0;
}

elsif ( $protocol eq "hagerehz363sml" ) {

	### Defaults
	our $baudrate = 9600 if !$baudrate;
	our $startbaudrate = 9600 if !$startbaudrate;
	our $databits = 8 if !$databits;
	our $stopbits = 1 if !$stopbits;
	our $parity = "none" if !$parity;
	our $handshake = "none" if !$handshake;
	our $timeout = "5" if !$timeout;
	our $delay = "1" if !$delay;
	our $crc = "CRC16_X_25" if !$crc;
	our $preinitcommand = "";
	our $precommand = "";
	our $postcommand = "";

	&PROTO_GENERICSML;
}

elsif ( $protocol eq "holleydtz541sml" ) {

	### Defaults
	our $baudrate = 9600 if !$baudrate;
	our $startbaudrate = 9600 if !$startbaudrate;
	our $databits = 8 if !$databits;
	our $stopbits = 1 if !$stopbits;
	our $parity = "none" if !$parity;
	our $handshake = "none" if !$handshake;
	our $timeout = "5" if !$timeout;
	our $delay = "1" if !$delay;
	our $crc = "CRC16_X_25" if !$crc;
	our $preinitcommand = "";
	our $precommand = "";
	our $postcommand = "";

	&PROTO_GENERICSML;
}

elsif ( $protocol eq "logarexlk13bdd0" ) {

	### Defaults
	our $baudrate = 4800 if !$baudrate;
	our $startbaudrate = 300 if !$startbaudrate;
	our $databits = 7 if !$databits;
	our $stopbits = 1 if !$stopbits;
	our $parity = "even" if !$parity;
	our $handshake = "none" if !$handshake;
	our $timeout = "5" if !$timeout;
	our $delay = "2" if !$delay;
	our $preinitcommand = "";
	our $precommand = "";
	our $postcommand = "";

	&PROTO_GENERICD0;
}

elsif ( $protocol eq "logarexlk13be8030d0" ) {

	### Defaults
	our $baudrate = 9600 if !$baudrate;
	our $startbaudrate = 9600 if !$startbaudrate;
	our $databits = 7 if !$databits;
	our $stopbits = 1 if !$stopbits;
	our $parity = "even" if !$parity;
	our $handshake = "none" if !$handshake;
	our $timeout = "4" if !$timeout;
	our $delay = "1" if !$delay;
	our $preinitcommand = "";
	our $precommand = "";
	our $postcommand = "";

	&PROTO_GENERICD0;
}

elsif ( $protocol eq "sagemcomsmartybzsml" ) {

	### Defaults
	our $baudrate = 9600 if !$baudrate;
	our $startbaudrate = 9600 if !$startbaudrate;
	our $databits = 8 if !$databits;
	our $stopbits = 1 if !$stopbits;
	our $parity = "none" if !$parity;
	our $handshake = "none" if !$handshake;
	our $timeout = "5" if !$timeout;
	our $delay = "1" if !$delay;
	our $crc = "CRC16_X_25" if !$crc;
	our $preinitcommand = "";
	our $precommand = "";
	our $postcommand = "";

	&PROTO_GENERICSML;
}

elsif ( $protocol eq "sagemcomt210d0" ) {

	### Defaults
	our $baudrate = 115200 if !$baudrate;
	our $startbaudrate = 115200 if !$startbaudrate;
	our $databits = 8 if !$databits;
	our $stopbits = 1 if !$stopbits;
	our $parity = "none" if !$parity;
	our $handshake = "none" if !$handshake;
	our $timeout = "20" if !$timeout;
	our $delay = "5" if !$delay;
	our $preinitcommand = "";
	our $precommand = "";
	our $postcommand = "";

	&PROTO_GENERICD0;
}

elsif ( $protocol eq "zpagh305sml" ) {

	### Defaults
	our $baudrate = 9600 if !$baudrate;
	our $startbaudrate = 9600 if !$startbaudrate;
	our $databits = 8 if !$databits;
	our $stopbits = 1 if !$stopbits;
	our $parity = "none" if !$parity;
	our $handshake = "none" if !$handshake;
	our $timeout = "5" if !$timeout;
	our $delay = "1" if !$delay;
	our $crc = "CRC16_X_25" if !$crc;
	our $preinitcommand = "";
	our $precommand = "";
	our $postcommand = "";

	&PROTO_GENERICSML;
}

else {
	$verbose =1;
	&LOG ("No known protocol specified. Try --help to get an overview of possible options.", "FAIL");
	exit;
}

################################
### Output
################################

&LOG("All data written to /dev/shm/$psubfolder/$serial.xxxx");

exit;


################################
###
### Subroutines
###
################################


################################
### Sub GENERIC D0 Protocol
################################

sub PROTO_GENERICD0
{

	my $type = shift; # http://wiki.selfhtml.org/wiki/Perl/Subroutinen

	if ( !$parse ) {

		&LOG ("Initial Baudrate: $startbaudrate", "INFO");
		&LOG ("Max Baudrate: $baudrate", "INFO");
		&LOG ("Protocol: $protocol", "INFO");
		&LOG ("Timeout: $timeout", "INFO");
		&LOG ("Delay: $delay", "INFO");

		### Open serial port
		&INITIALIZE_PORT();

		### Sending Starting Sequenze
		&D0_STARTINGSEQUENZE("2f3f210d0a", "$preinitcommand");

		### Changing Baudrate
		### Change baud rate only if different
		if ( $startbaudrate ne $baudrate ) {
    			&D0_CHANGEBAUDRATE("$baudrate", "$precommand", "$postcommand");
    		}

    		### Read serial device
    		&READ_SERIAL();
    
    	} else {
    
    		&LOG ("Parsing previous dump file $parse", "INFO");
    
    	}
    
    	if ( $type eq "HEAT" ) {

    		&PARSE_DUMP("D0", "HEAT");
		
    	} elsif ( $type eq "FLANDERS" ) {

    		&PARSE_DUMP("D0", "FLANDERS");

		} else {

			&PARSE_DUMP("D0");

	}

	return;

}

################################
### Sub GENERIC SML Protocol
################################

sub PROTO_GENERICSML
{

	if ( !$parse ) {

		&LOG ("Initial Baudrate: $startbaudrate", "INFO");
		&LOG ("Max Baudrate: $baudrate", "INFO");
		&LOG ("Protocol: $protocol", "INFO");
		&LOG ("Timeout: $timeout", "INFO");
		&LOG ("Delay: $delay", "INFO");
		&LOG ("CRC: $crc", "INFO");

		### Open serial port
		&INITIALIZE_PORT();

		### Read serial device
		&READ_SERIAL("HEX");

	} else {

		&LOG ("Parsing previous dump file $parse", "INFO");

	}

	&PARSE_DUMP("SML");

	return;

}

################################
### SUB: D0 Send Starting Sequenze
################################

sub D0_STARTINGSEQUENZE
{

	### Debug output
	&LOG ("Sending D0 Starting Sequence", "INFO");

	### Send Initial Sequenze
	my $data = shift; # http://wiki.selfhtml.org/wiki/Perl/Subroutinen
	my $init = shift; # http://wiki.selfhtml.org/wiki/Perl/Subroutinen
	if ( !$data) { $data = "2f3f210d0a" }; # Std. if empty: Send as HEX "/?!<CR><LF>"

	# PreInit
	if ($init) {
		my $request = pack('H*',$init);
		my $requestlog = "$request";
		$requestlog =~ s/\r\n\z//; # chomp doesn't work here...
		my $num_out = $port->write($request);

		### Debug
  		&LOG ("Send: $requestlog", "INFO");
		if ( !$num_out ) {
			$verbose = 1;
			&LOG ("Write failed.", "FAIL");
			exit;
		}
		if ( $num_out ne length($request) ) {
			$verbose = 1;
			&LOG ("Write incomplete.", "FAIL");
			exit;
		}
		&LOG ("$num_out Bytes written.", "INFO");

		sleep 0.5;
	}

	# Initialize
	$request = pack('H*',$data);
	$requestlog = "$request";
	$requestlog =~ s/\r\n\z//; # chomp doesn't work here...
	$num_out = $port->write($request);

	### Debug
  	&LOG ("Send: $requestlog", "INFO");
	if ( !$num_out ) {
		$verbose = 1;
		&LOG ("Write failed.", "FAIL");
		exit;
	}
	if ( $num_out ne length($request) ) {
		$verbose = 1;
		&LOG ("Write incomplete.", "FAIL");
		exit;
	}
	&LOG ("$num_out Bytes written.", "INFO");

	return();
}

################################
### SUB: D0 Change Baudrate
################################

sub D0_CHANGEBAUDRATE
{

	our $baudratetarget = shift;
	our $precmd = shift; # http://wiki.selfhtml.org/wiki/Perl/Subroutinen
	our $postcmd = shift; # http://wiki.selfhtml.org/wiki/Perl/Subroutinen

	### Wait for Meter
	sleep $delay;

	### Change Baudrate
	### 303030 (Ascii: 000) = 300baud
	### 303330 (Ascii: 030) = 2400baud
	### 303430 (Ascii: 040) = 4800baud
	### 303530 (Ascii: 050) = 9600baud
	### 303530 (Ascii: 060) = 19200baud
	if ( $baudratetarget eq "300" ) {
		our $baudchange = 1;
		our $baudrateh = "303030";
	}
	elsif ( $baudratetarget eq "2400" ) {
		our $baudchange = 1;
		our $baudrateh = "303330";
	}
	elsif ( $baudratetarget eq "4800" ) {
		our $baudchange = 1;
		our $baudrateh = "303430";
	}
	elsif ( $baudratetarget eq "9600" ) {
 	 	our $baudchange = 1;
  		our $baudrateh = "303530";
	}
	elsif ( $baudratetarget eq "19200" ) {
 	 	our $baudchange = 1;
  		our $baudrateh = "303630";
	} else {
		&LOG ("The baudrate $baudratetarget is not implemented by this protocol. Using default baudrate: 300 baud.", "WARNING");
		our $baudchange = 0;
		our $baudrate = 300;
	}

	### If we should change the baudrate, send ACK and sequence in hex
	if ($baudchange == 1){

		### Debug
		&LOG ("Changing Baudrate to $baudratetarget", "INFO");

		# Pre-Command to change Baudrate
		if ( $precmd ) {
  			my $data3="06".$precmd."0d0a"; # ACK and precmd in HEX, z. B. "<ACK>040<CR><LF>"
	  		my $precommand = pack('H*',$data3);
			my $precommandlog = $precommand;
			$precommandlog =~ s/\r\n\z//; # chomp doesn't work here...
  			my $num_out3 = $port->write($precommand);
	
			### Debug
  			&LOG ("Send: $precommandlog", "INFO");
			if ( !$num_out3 ) {
				$verbose = 1;
				&LOG ("Write failed.", "FAIL");
				exit;
			}
			if ( $num_out3 ne length($precommand) ) {
				$verbose = 1;
				&LOG ("Write incomplete.", "FAIL");
				exit;
			}
			&LOG ("$num_out3 Bytes written.", "INFO");
		}

		# Command to change Baudrate
  		my $data2="06".$baudrateh."0d0a"; # ACK and new baudrate in HEX, z. B. "<ACK>040<CR><LF>"
  		my $baudwechsel = pack('H*',$data2);
		my $baudwechsellog = $baudwechsel;
		$baudwechsellog =~ s/\r\n\z//; # chomp doesn't work here...
  		my $num_out2 = $port->write($baudwechsel);

		### Debug
  		&LOG ("Send: $baudwechsellog", "INFO");
		if ( !$num_out2 ) {
			$verbose = 1;
			&LOG ("Write failed.", "FAIL");
			exit;
		}
		if ( $num_out2 ne length($baudwechsel) ) {
			$verbose = 1;
			&LOG ("Write incomplete.", "FAIL");
			exit;
		}
		&LOG ("$num_out2 Bytes written.", "INFO");

		# Post-Command to change Baudrate
		if ( $postcmd ) {
	  		my $baudwechsel = pack('H*',$postcmd);
			my $baudwechsellog = $baudwechsel;
			$baudwechsellog =~ s/\r\n\z//; # chomp doesn't work here...
  			my $num_out2 = $port->write($baudwechsel);
	
			### Debug
  			&LOG ("Send: $baudwechsellog", "INFO");
			if ( !$num_out2 ) {
				$verbose = 1;
				&LOG ("Write failed.", "FAIL");
				exit;
			}
			if ( $num_out2 ne length($baudwechsel) ) {
				$verbose = 1;
				&LOG ("Write incomplete.", "FAIL");
				exit;
			}
			&LOG ("$num_out2 Bytes written.", "INFO");
		}

		### Activate new baudrate on device
		sleep 1;
		$port->baudrate($baudratetarget)      || die "Fail setting baudrate. Giving up.\n";

	}

	return();

}

################################
### SUB: Initialize Serial Port
################################

sub INITIALIZE_PORT
{

	### Debug output
	&LOG ("Setting up port $device: Baudrate:$baudrate/$startbaudrate Databits:$databits Stopbits:$stopbits Parity:$parity Handshake:$handshake", "INFO");

	### Open Port
	our $port = new Device::SerialPort($device) || die "Can't open $device: $!. Giving up.\n";
	$port->baudrate($startbaudrate);
	$port->databits($databits);
	$port->stopbits($stopbits);
	$port->parity("$parity");
	$port->handshake("$handshake");
	$port->dtr_active(1);
	$port->rts_active(1);
	$port->read_char_time(0); # 0 seconds for each character
	$port->read_const_time(1000); # 1 second per unfulfilled "read" call
	$port->write_settings || die "Fail write settings to device. Giving up.\n";
	$port->purge_all();

	return();

}

################################
### SUB: Read buffer from serial device
################################

################################
### SUB: Datendatei atomar schreiben
###
### Bis 2.3.2 stand hier dreimal open(F,">.../$serial.data") - die Datei
### wurde also GEKUERZT und dann neu gefuellt. Zwischen beidem ist sie leer,
### und genau in dieses Fenster kann der Miniserver hineinlesen: index.php
### liefert die Datei mit readfile() aus, ohne Sperre.
###
### Nachgemessen (ein Schreiber, ein Leser, /dev/shm):
###   kuerzen und fuellen : 69,75 % der Lesevorgaenge unvollstaendig oder leer
###   temp + rename       :  0,00 %
### Das unbrauchbare Fenster dauert rund 35 Mikrosekunden je Schreibvorgang.
### Bei einem Abruf je Minute ist das selten - aber wenn es trifft, steht in
### der Loxone-Statistik eine 0, fuer die es keine Erklaerung gibt.
###
### rename() innerhalb desselben Dateisystems ist unteilbar: der Leser sieht
### entweder die alte oder die neue Datei, nie einen Zwischenstand.
################################

sub DATA_OEFFNEN
{
	$sm_datendatei = "/dev/shm/$psubfolder/$serial" . ".data";
	$sm_datentmp   = $sm_datendatei . ".tmp." . $$;
	if (!open(F, ">$sm_datentmp")) {
		&LOG ("Datendatei nicht schreibbar: $sm_datentmp", "ERROR");
		return 0;
	}
	# Der Zaehler der wirklich geschriebenen Messwerte beginnt bei jedem
	# Oeffnen neu.
	$sm_gemessen = 0;
	return 1;
}

################################
### SUB: einen gemessenen Wert schreiben
###
### Bis 2.3.14 stand die Bedingung "nur schreiben, wenn nicht leer" an 99
### Stellen ausgeschrieben. Jetzt steht sie einmal hier - und nebenbei
### zaehlt sie mit, wie viele Werte ein Durchlauf wirklich gebracht hat.
### Genau diese Zahl entscheidet, ob Last_UpdateUnix entsteht.
################################

sub DATA_WERT
{
	my ($name, $wert) = @_;
	return 0 if !defined $wert || $wert eq "";
	print F "$serial:$name:$wert" . "\n";
	$sm_gemessen++;
	return 1;
}

sub DATA_SCHLIESSEN
{
	my ($lesbar, $loxepoche) = @_;

	# Die Zeitstempel stehen am ENDE, weil erst dann feststeht, ob ueberhaupt
	# etwas gemessen wurde. Fuer Loxone ist die Reihenfolge gleichgueltig -
	# dort wird nach dem Feldnamen gesucht, nicht nach der Zeile.
	print F "$serial:Last_Update:$lesbar" . "\n"                 if defined $lesbar;
	print F "$serial:Last_UpdateLoxEpoche:$loxepoche" . "\n"     if defined $loxepoche;

	# Last_UpdateUnix ist der Zeitstempel der MESSUNG, nicht des
	# Schreibvorgangs. Der Endpunkt rechnet daraus das ALTER zur Lesezeit.
	# Hat dieser Durchlauf keinen einzigen Wert gebracht, entsteht er NICHT:
	# ein Zeitstempel ohne Messung dahinter meldet "gerade eben gemessen",
	# waehrend seit Stunden nichts ankommt - die stille Falschaussage in
	# Reinform. Der alte Wert bleibt dann in der vorigen Datei stehen, und
	# das Alter waechst. Das ist die wahre Aussage: die Werte sind alt,
	# nicht weg.
	if ( $sm_gemessen > 0 ) {
		print F "$serial:Last_UpdateUnix:" . time() . "\n";
	} else {
		&LOG("Kein einziger Messwert in diesem Durchlauf - Last_UpdateUnix wird NICHT geschrieben.", "WARN");
	}

	close (F);
	if (!rename($sm_datentmp, $sm_datendatei)) {
		&LOG ("Datendatei liess sich nicht umbenennen: $sm_datentmp", "ERROR");
		unlink($sm_datentmp);
		return 0;
	}
	return 1;
}

sub READ_SERIAL
{

	our $hex = shift;

	### Read answer of meter until end
	#
	# HIER LAG BIS 2.3.2 EIN SCHATTEN, DER JEDE ABFRAGE AUF DIE VOLLE
	# ZEITGRENZE GEZOGEN HAT.
	#
	# Es stand da:
	#     our $count = 5;
	#     while ($count > 0) {
	#         my ($count, $saw) = $port->read(255);   # <-- eigenes $count!
	#         ...
	#         else { $count--; }                      # zaehlt das INNERE herunter
	#     }
	# Das "my" in der Schleife legt eine NEUE Variable an, die bei jedem
	# Durchlauf neu entsteht. Heruntergezaehlt wurde also immer nur diese;
	# die Bedingung der while-Schleife steht ausserhalb ihres Gueltigkeits-
	# bereichs und sah weiterhin die aeussere Variable mit dem Wert 5.
	#
	# Nachgestellt mit derselben Struktur und einem Port, der nichts liefert:
	#     mit "my":   200000 Runden, aeusseres count = 5   (Notbremse gezogen)
	#     ohne "my":       5 Runden, aeusseres count = 0
	# Die Schleife endete also NIE von selbst - beendet hat sie
	# ausschliesslich das alarm(). Folge: jede Abfrage dauerte genau so
	# lange wie die Zeitgrenze des Profils, also je nach Zaehler 5 bis 120
	# Sekunden, auch wenn der Zaehler sofort geantwortet hatte. Daher auch
	# die minutenlang haengende Oberflaeche beim Knopf "Jetzt abfragen".
	#
	# Zwei Netze, nicht eines: der Zaehler beendet die Schleife jetzt selbst,
	# und zusaetzlich begrenzen Rundenzahl und Puffergroesse. Ein Zaehler,
	# der ununterbrochen Muell sendet, wuerde sonst weiterhin bis zum alarm
	# lesen und dabei den Arbeitsspeicher fuellen.
	my $leerlauf = 5;          # so viele Leerlesungen gelten als "fertig"
	my $runden   = 0;
	my $MAXRUNDEN = 100000;    # Notbremse gegen Dauerlieferer
	my $MAXPUFFER = 1048576;   # 1 MiB - ein Telegramm ist ein Bruchteil davon
	our $buffer = "";
	local $SIG{ALRM} = sub { die };
	eval {
  		alarm($timeout);
		while ($leerlauf > 0) {
			if (++$runden > $MAXRUNDEN) {
				&LOG ("READ_SERIAL: Rundengrenze erreicht - Lesen abgebrochen.", "WARN");
				last;
			}
			my ($gelesen, $saw) = $port->read(255); # Read 255 signs each
  			if ($gelesen && $gelesen > 0) {
				$buffer .= $saw;
				if (length($buffer) > $MAXPUFFER) {
					&LOG ("READ_SERIAL: Puffergrenze erreicht - Lesen abgebrochen.", "WARN");
					last;
				}
				### Debug: print received signs
				if ($verbose){
					if ($hex eq "HEX"){
						$x = uc(unpack('H*',$saw)); # nach hex wandeln
						print $x;
					} else {
						print $saw;
					}
				}
			} else {
				$leerlauf--;
			}
		}
	};
	alarm(0);

	if ($verbose){
		print "\n";
	}

	# Close port
	$port->close;

	# Save output to file and convert line endings
	&LOG ("Save raw buffer to /dev/shm/$psubfolder/$serial\.dump", "INFO");
	if ($hex eq "HEX"){
		$bufferx = uc(unpack('H*',$buffer)); # nach hex wandeln
	}
	open(F,">>/dev/shm/$psubfolder/$serial\.dump");
		if ($hex eq "HEX"){
			print F $bufferx;
		} else {
			print F $buffer;
		}
	close (F);
	system("/usr/bin/dos2unix -f /dev/shm/$psubfolder/$serial\.dump > /dev/null 2>&1");

	if ($hex eq "HEX"){
		return($bufferx);
	} else {
		return($buffer);
	}

}

################################
### SUB: Parse D0
################################

sub PARSE_DUMP
{

	our $proto = shift;
	our $type = shift; # http://wiki.selfhtml.org/wiki/Perl/Subroutinen
	if ($proto eq "SML") {
		&LOG ("Parse /dev/shm/$psubfolder/$serial\.dump as SML-Protocol.", "INFO");
		our $dumpbuffer = `php $home/bin/plugins/$psubfolder/sml_parser.php /dev/shm/$psubfolder/$serial\.dump $crc`;
		print "Buffer: $dumpbuffer\n";
	} else {
		&LOG ("Parse /dev/shm/$psubfolder/$serial\.dump as D0-Protocol.", "INFO");
		open(F,"</dev/shm/$psubfolder/$serial\.dump");
			our $dumpbuffer = do { local $/; <F> };
		close (F);
	}

	if ( $type eq "HEAT" ) {
		### Energy consumption: Readings for Siemens UH50 / Landis+Gyr ULTRAHEAT T550
		($readingconsT0) = $dumpbuffer =~ /[\n|\r|:|\)]*6\.8[\.0]*[\*255|\*00]*\(([\d\.]+)/;
		($readingconsT1) = $dumpbuffer =~ /[\n|\r|:|\)]6\.8\.1[\*255|\*00]*\(([\d\.]+)/;
		($readingconsT2) = $dumpbuffer =~ /[\n|\r|:|\)]6\.8\.2[\*255|\*00]*\(([\d\.]+)/;
		($readingconsT3) = $dumpbuffer =~ /[\n|\r|:|\)]6\.8\.3[\*255|\*00]*\(([\d\.]+)/;
		($readingconsT4) = $dumpbuffer =~ /[\n|\r|:|\)]6\.8\.4[\*255|\*00]*\(([\d\.]+)/;
		($readingconsT5) = $dumpbuffer =~ /[\n|\r|:|\)]6\.8\.5[\*255|\*00]*\(([\d\.]+)/;
		($readingconsT6) = $dumpbuffer =~ /[\n|\r|:|\)]6\.8\.6[\*255|\*00]*\(([\d\.]+)/;
		($readingconsT7) = $dumpbuffer =~ /[\n|\r|:|\)]6\.8\.7[\*255|\*00]*\(([\d\.]+)/;
		($readingconsT8) = $dumpbuffer =~ /[\n|\r|:|\)]6\.8\.8[\*255|\*00]*\(([\d\.]+)/;
		($readingconsT9) = $dumpbuffer =~ /[\n|\r|:|\)]6\.8\.9[\*255|\*00]*\(([\d\.]+)/;

		### Energy delivery: Readings  (OBIS 2.8.x*255)
		#($readingdelT0) = $dumpbuffer =~ /[\n|\r|:]2\.8\.0[\*255|\*00]*\(([\d\.]+)/;
		#($readingdelT1) = $dumpbuffer =~ /[\n|\r|:]2\.8\.1[\*255|\*00]*\(([\d\.]+)/;
		#($readingdelT2) = $dumpbuffer =~ /[\n|\r|:]2\.8\.2[\*255|\*00]*\(([\d\.]+)/;
		#($readingdelT3) = $dumpbuffer =~ /[\n|\r|:]2\.8\.3[\*255|\*00]*\(([\d\.]+)/;
		#($readingdelT4) = $dumpbuffer =~ /[\n|\r|:]2\.8\.4[\*255|\*00]*\(([\d\.]+)/;
		#($readingdelT5) = $dumpbuffer =~ /[\n|\r|:]2\.8\.5[\*255|\*00]*\(([\d\.]+)/;
		#($readingdelT6) = $dumpbuffer =~ /[\n|\r|:]2\.8\.6[\*255|\*00]*\(([\d\.]+)/;
		#($readingdelT7) = $dumpbuffer =~ /[\n|\r|:]2\.8\.7[\*255|\*00]*\(([\d\.]+)/;
		#($readingdelT8) = $dumpbuffer =~ /[\n|\r|:]2\.8\.8[\*255|\*00]*\(([\d\.]+)/;
		#($readingdelT9) = $dumpbuffer =~ /[\n|\r|:]2\.8\.9[\*255|\*00]*\(([\d\.]+)/;

		### Energy consumption: Power  (OBIS mixture - no standard?)
		($power1) = $dumpbuffer =~ /[\n|\r|:]*6\.6[\.0]*[\*255|\*00]*\(([\d\.]+)/;
		($volume1) = $dumpbuffer =~ /[\n|\r|:]*6\.26[\.0]*[\*255|\*00]*\(([\d\.]+)/;
		($hour1) = $dumpbuffer =~ /[\n|\r|:]*6\.31[\.0]*[\*255|\*00]*\(([\d\.]+)/;
		($hour2) = $dumpbuffer =~ /[\n|\r|:]*6\.32[\.0]*[\*255|\*00]*\(([\d\.]+)/;
		($flow1) = $dumpbuffer =~ /[\n|\r|:]*6\.33[\.0]*[\*255|\*00]*\(([\d\.]+)/;
		($hour3) = $dumpbuffer =~ /[\n|\r|:]*9\.31[\.0]*[\*255|\*00]*\(([\d\.]+)/;
		($heating_flow) = $dumpbuffer =~ /[\n|\r|:]*9\.4[\.0]*[\*255|\*00]*\(([\d\.]+)/;
		($heating_return) = $dumpbuffer =~ /[\n|\r|:]*9\.4\([^&]+&([^*]+)/;

	}
	elsif ( $type eq "FLANDERS" ) {
		### Energy consumption: Readings  (OBIS 1.8.x*255)
		($readingconsT0) = $dumpbuffer =~ /[\n|\r|:]1\.8\.0[\*255|\*00]*\(([\d\.]+)/;
		($readingconsT1) = $dumpbuffer =~ /[\n|\r|:]1\.8\.1[\*255|\*00]*\(([\d\.]+)/;
		($readingconsT2) = $dumpbuffer =~ /[\n|\r|:]1\.8\.2[\*255|\*00]*\(([\d\.]+)/;
		($readingconsT3) = $dumpbuffer =~ /[\n|\r|:]1\.8\.3[\*255|\*00]*\(([\d\.]+)/;
		($readingconsT4) = $dumpbuffer =~ /[\n|\r|:]1\.8\.4[\*255|\*00]*\(([\d\.]+)/;
		($readingconsT5) = $dumpbuffer =~ /[\n|\r|:]1\.8\.5[\*255|\*00]*\(([\d\.]+)/;
		($readingconsT6) = $dumpbuffer =~ /[\n|\r|:]1\.8\.6[\*255|\*00]*\(([\d\.]+)/;
		($readingconsT7) = $dumpbuffer =~ /[\n|\r|:]1\.8\.7[\*255|\*00]*\(([\d\.]+)/;
		($readingconsT8) = $dumpbuffer =~ /[\n|\r|:]1\.8\.8[\*255|\*00]*\(([\d\.]+)/;
		($readingconsT9) = $dumpbuffer =~ /[\n|\r|:]1\.8\.9[\*255|\*00]*\(([\d\.]+)/;

		### Energy delivery: Readings  (OBIS 2.8.x*255)
		($readingdelT0) = $dumpbuffer =~ /[\n|\r|:]2\.8\.0[\*255|\*00]*\(([\d\.]+)/;
		($readingdelT1) = $dumpbuffer =~ /[\n|\r|:]2\.8\.1[\*255|\*00]*\(([\d\.]+)/;
		($readingdelT2) = $dumpbuffer =~ /[\n|\r|:]2\.8\.2[\*255|\*00]*\(([\d\.]+)/;
		($readingdelT3) = $dumpbuffer =~ /[\n|\r|:]2\.8\.3[\*255|\*00]*\(([\d\.]+)/;
		($readingdelT4) = $dumpbuffer =~ /[\n|\r|:]2\.8\.4[\*255|\*00]*\(([\d\.]+)/;
		($readingdelT5) = $dumpbuffer =~ /[\n|\r|:]2\.8\.5[\*255|\*00]*\(([\d\.]+)/;
		($readingdelT6) = $dumpbuffer =~ /[\n|\r|:]2\.8\.6[\*255|\*00]*\(([\d\.]+)/;
		($readingdelT7) = $dumpbuffer =~ /[\n|\r|:]2\.8\.7[\*255|\*00]*\(([\d\.]+)/;
		($readingdelT8) = $dumpbuffer =~ /[\n|\r|:]2\.8\.8[\*255|\*00]*\(([\d\.]+)/;
		($readingdelT9) = $dumpbuffer =~ /[\n|\r|:]2\.8\.9[\*255|\*00]*\(([\d\.]+)/;

		### Energy consumption: Power  (OBIS mixture - no standard?)
		($power1) = $dumpbuffer =~ /[\n|\r|:]1\.7(?:\.0)?[\*255|\*00]*\(([-\d\.]+)/;
		($power2) = $dumpbuffer =~ /[\n|\r|:]2\.7(?:\.0)?[\*255|\*00]*\(([-\d\.]+)/;
		($power3) = $dumpbuffer =~ /[\n|\r|:]15\.7(?:\.0)?[\*255|\*00]*\(([-\d\.]+)/;
		($power4) = $dumpbuffer =~ /[\n|\r|:]16\.7(?:\.0)?[\*255|\*00]*\(([-\d\.]+)/;
		($power5) = $dumpbuffer =~ /[\n|\r|:]21\.7(?:\.0)?[\*255|\*00]*\(([-\d\.]+)/;
		($power6) = $dumpbuffer =~ /[\n|\r|:]41\.7(?:\.0)?[\*255|\*00]*\(([-\d\.]+)/;
		($power7) = $dumpbuffer =~ /[\n|\r|:]61\.7(?:\.0)?[\*255|\*00]*\(([-\d\.]+)/;

		### Instantaneous voltage
		($volt1) = $dumpbuffer =~ /[\n|\r|:]32\.7(?:\.0)?[\*255|\*00]*\(([-\d\.]+)/;
		($volt2) = $dumpbuffer =~ /[\n|\r|:]52\.7(?:\.0)?[\*255|\*00]*\(([-\d\.]+)/;
		($volt3) = $dumpbuffer =~ /[\n|\r|:]72\.7(?:\.0)?[\*255|\*00]*\(([-\d\.]+)/;
		($current1) = $dumpbuffer =~ /[\n|\r|:]31\.7(?:\.0)?[\*255|\*00]*\(([-\d\.]+)/;
		($current2) = $dumpbuffer =~ /[\n|\r|:]51\.7(?:\.0)?[\*255|\*00]*\(([-\d\.]+)/;
		($current3) = $dumpbuffer =~ /[\n|\r|:]71\.7(?:\.0)?[\*255|\*00]*\(([-\d\.]+)/;
		
		### Equipment Data
		($eid) = $dumpbuffer =~ /[\n|\r|:]96\.1\.1[\*255|\*00]*\(([-\d\.]+)/;
		$eid =~ s/([[:xdigit:]]{2})/chr(hex($1))/eg;
		($version) = $dumpbuffer =~ /[\n|\r|:]96\.1\.4[\*255|\*00]*\(([-\d\.]+)/;
		($currenttarif) = $dumpbuffer =~ /[\n|\r|:]96\.14\.0[\*255|\*00]*\(([-\d\.]+)/;
		($breakerstate) = $dumpbuffer =~ /[\n|\r|:]96\.3\.10[\*255|\*00]*\(([-\d\.]+)/;
		($messagecode) = $dumpbuffer =~ /[\n|\r|:]96\.13\.1[\*255|\*00]*\(([-\d\.]+)/;
		($messagetext) = $dumpbuffer =~ /[\n|\r|:]96\.13\.0[\*255|\*00]*\(([-\d\.]+)/;
		
	}	else {

		### Energy consumption: Readings  (OBIS 1.8.x*255)
		($readingconsT0) = $dumpbuffer =~ /[\n|\r|:]1\.8\.0[\*255|\*00]*\(([\d\.]+)/;
		($readingconsT1) = $dumpbuffer =~ /[\n|\r|:]1\.8\.1[\*255|\*00]*\(([\d\.]+)/;
		($readingconsT2) = $dumpbuffer =~ /[\n|\r|:]1\.8\.2[\*255|\*00]*\(([\d\.]+)/;
		($readingconsT3) = $dumpbuffer =~ /[\n|\r|:]1\.8\.3[\*255|\*00]*\(([\d\.]+)/;
		($readingconsT4) = $dumpbuffer =~ /[\n|\r|:]1\.8\.4[\*255|\*00]*\(([\d\.]+)/;
		($readingconsT5) = $dumpbuffer =~ /[\n|\r|:]1\.8\.5[\*255|\*00]*\(([\d\.]+)/;
		($readingconsT6) = $dumpbuffer =~ /[\n|\r|:]1\.8\.6[\*255|\*00]*\(([\d\.]+)/;
		($readingconsT7) = $dumpbuffer =~ /[\n|\r|:]1\.8\.7[\*255|\*00]*\(([\d\.]+)/;
		($readingconsT8) = $dumpbuffer =~ /[\n|\r|:]1\.8\.8[\*255|\*00]*\(([\d\.]+)/;
		($readingconsT9) = $dumpbuffer =~ /[\n|\r|:]1\.8\.9[\*255|\*00]*\(([\d\.]+)/;

		### Energy delivery: Readings  (OBIS 2.8.x*255)
		($readingdelT0) = $dumpbuffer =~ /[\n|\r|:]2\.8\.0[\*255|\*00]*\(([\d\.]+)/;
		($readingdelT1) = $dumpbuffer =~ /[\n|\r|:]2\.8\.1[\*255|\*00]*\(([\d\.]+)/;
		($readingdelT2) = $dumpbuffer =~ /[\n|\r|:]2\.8\.2[\*255|\*00]*\(([\d\.]+)/;
		($readingdelT3) = $dumpbuffer =~ /[\n|\r|:]2\.8\.3[\*255|\*00]*\(([\d\.]+)/;
		($readingdelT4) = $dumpbuffer =~ /[\n|\r|:]2\.8\.4[\*255|\*00]*\(([\d\.]+)/;
		($readingdelT5) = $dumpbuffer =~ /[\n|\r|:]2\.8\.5[\*255|\*00]*\(([\d\.]+)/;
		($readingdelT6) = $dumpbuffer =~ /[\n|\r|:]2\.8\.6[\*255|\*00]*\(([\d\.]+)/;
		($readingdelT7) = $dumpbuffer =~ /[\n|\r|:]2\.8\.7[\*255|\*00]*\(([\d\.]+)/;
		($readingdelT8) = $dumpbuffer =~ /[\n|\r|:]2\.8\.8[\*255|\*00]*\(([\d\.]+)/;
		($readingdelT9) = $dumpbuffer =~ /[\n|\r|:]2\.8\.9[\*255|\*00]*\(([\d\.]+)/;

		### Energy consumption: Power  (OBIS mixture - no standard?)
		($power1) = $dumpbuffer =~ /[\n|\r|:]1\.7\.(?:255|0)[\*255|\*00]*\(([-\d\.]+)/;
		($power2) = $dumpbuffer =~ /[\n|\r|:]2\.7\.0[\*255|\*00]*\(([-\d\.]+)/;
		($power3) = $dumpbuffer =~ /[\n|\r|:]15\.7\.0[\*255|\*00]*\(([-\d\.]+)/;
		($power4) = $dumpbuffer =~ /[\n|\r|:]16\.7\.0[\*255|\*00]*\(([-\d\.]+)/;
		($power5) = $dumpbuffer =~ /[\n|\r|:]21\.7\.(?:255|0)[\*255|\*00]*\(([-\d\.]+)/;
		($power6) = $dumpbuffer =~ /[\n|\r|:]41\.7\.(?:255|0)[\*255|\*00]*\(([-\d\.]+)/;
		($power7) = $dumpbuffer =~ /[\n|\r|:]61\.7\.(?:255|0)[\*255|\*00]*\(([-\d\.]+)/;
		($power8) = $dumpbuffer =~ /[\n|\r|:]36\.7\.(?:255|0)[\*255|\*00]*\(([-\d\.]+)/;
		($power9) = $dumpbuffer =~ /[\n|\r|:]56\.7\.(?:255|0)[\*255|\*00]*\(([-\d\.]+)/;
		($power10) = $dumpbuffer =~ /[\n|\r|:]76\.7\.(?:255|0)[\*255|\*00]*\(([-\d\.]+)/;
        ($del_cons) = $dumpbuffer =~ /[\n|\r|:]C\.5\.(?:255|0)[\*255|\*00]*\(([-\d\.]+)/;
	}

	### Calculate Avg. Power
	my $powercalccons = &CALCULATE_POWER("$readingconsT0","CONS");
	my $powercalcdel = &CALCULATE_POWER("$readingdelT0","DEL");

	# Today's date for LOGfile
	(my $sec,my $min,my $hour,my $mday,my $mon,my $year,my $wday,my $yday,my $isdst) = localtime();
	$year = $year+1900;
	$mon = $mon+1;
	$mon = sprintf("%02d", $mon);
	$mday = sprintf("%02d", $mday);
	$hour = sprintf("%02d", $hour);
	$min = sprintf("%02d", $min);
	$sec = sprintf("%02d", $sec);

	my $datereadable = "$year-$mon-$mday $hour:$min:$sec";

	# Loxone Epoche Date
	my $dt = DateTime->new( year   => $year, month  => $mon, day    => $mday, hour   => $hour, minute => $min, 
				second => $sec, nanosecond => 500000000, time_zone => 'local' );
	my $epoch_time = $dt->epoch;
	my $tz = DateTime::TimeZone->new( name => 'local' );
	my $offset = $tz->offset_for_datetime($dt);

	# Date Reference: Convert into Loxone Epoche (1.1.2009)
	my $dateref = DateTime->new(
		year      => 2009,
		month     => 1,
		day       => 1,
	);
	my $epoche_time_lox = $epoch_time - $dateref->epoch() + $offset;

#	print "Epoche Date: $epoch_time\n";
#	print "Epoche Date Lox: $epoche_time_lox\n";
#	print "Offset: $offset\n";

	### Save to data file
	&LOG ("Save Meter data to /dev/shm/$psubfolder/$serial\.data.", "INFO");

	if ( $type eq "HEAT" ) {

		&DATA_OEFFNEN();
				&DATA_WERT("Consumption_Total_OBIS_6.8.0", $readingconsT0);
		&DATA_WERT("Consumption_Tarif1_OBIS_6.8.1", $readingconsT1);
		&DATA_WERT("Consumption_Tarif2_OBIS_6.8.2", $readingconsT2);
		&DATA_WERT("Consumption_Tarif3_OBIS_6.8.3", $readingconsT3);
		&DATA_WERT("Consumption_Tarif4_OBIS_6.8.4", $readingconsT4);
		&DATA_WERT("Consumption_Tarif5_OBIS_6.8.5", $readingconsT5);
		&DATA_WERT("Consumption_Tarif6_OBIS_6.8.6", $readingconsT6);
		&DATA_WERT("Consumption_Tarif7_OBIS_6.8.7", $readingconsT7);
		&DATA_WERT("Consumption_Tarif8_OBIS_6.8.8", $readingconsT8);
		&DATA_WERT("Consumption_Tarif9_OBIS_6.8.9", $readingconsT9);
		&DATA_WERT("Consumption_CalculatedPower_OBIS_1.99.0", $powercalccons);
		&DATA_WERT("Max_Power_OBIS_6.6.0", $power1);
		&DATA_WERT("Volume_OBIS_6.26.0", $volume1);
		&DATA_WERT("Hour_OBIS_6.31.0", $hour1);
		&DATA_WERT("Hour_OBIS_6.32.0", $hour2);
		&DATA_WERT("Hour_OBIS_9.31.0", $hour3);
		&DATA_WERT("Flow_OBIS_6.33.0", $flow1);
		&DATA_WERT("Heating_Flow_OBIS_9.4", $heating_flow);
		&DATA_WERT("Heating_Return_OBIS_9.4", $heating_return);
		&DATA_SCHLIESSEN($datereadable, $epoche_time_lox);

	}
	elsif ( $type eq "FLANDERS" ) {
	
		&DATA_OEFFNEN();
				&DATA_WERT("Consumption_Total_OBIS_1.8.0", $readingconsT0);
		&DATA_WERT("Consumption_Tarif1_OBIS_1.8.1", $readingconsT1);
		&DATA_WERT("Consumption_Tarif2_OBIS_1.8.2", $readingconsT2);
		&DATA_WERT("Consumption_Tarif3_OBIS_1.8.3", $readingconsT3);
		&DATA_WERT("Consumption_Tarif4_OBIS_1.8.4", $readingconsT4);
		&DATA_WERT("Consumption_Tarif5_OBIS_1.8.5", $readingconsT5);
		&DATA_WERT("Consumption_Tarif6_OBIS_1.8.6", $readingconsT6);
		&DATA_WERT("Consumption_Tarif7_OBIS_1.8.7", $readingconsT7);
		&DATA_WERT("Consumption_Tarif8_OBIS_1.8.8", $readingconsT8);
		&DATA_WERT("Consumption_Tarif9_OBIS_1.8.9", $readingconsT9);
		&DATA_WERT("Consumption_CalculatedPower_OBIS_1.99.0", $powercalccons);
		&DATA_WERT("Consumption_Power_OBIS_1.7.0", $power1);
		&DATA_WERT("Consumption_Power_L1_OBIS_21.7.0", $power5);
		&DATA_WERT("Consumption_Power_L2_OBIS_41.7.0", $power6);
		&DATA_WERT("Consumption_Power_L3_OBIS_61.7.0", $power7);
		&DATA_WERT("Delivery_Total_OBIS_2.8.0", $readingdelT0);
		&DATA_WERT("Delivery_Tarif1_OBIS_2.8.1", $readingdelT1);
		&DATA_WERT("Delivery_Tarif2_OBIS_2.8.2", $readingdelT2);
		&DATA_WERT("Delivery_Tarif3_OBIS_2.8.3", $readingdelT3);
		&DATA_WERT("Delivery_Tarif4_OBIS_2.8.4", $readingdelT4);
		&DATA_WERT("Delivery_Tarif5_OBIS_2.8.5", $readingdelT5);
		&DATA_WERT("Delivery_Tarif6_OBIS_2.8.6", $readingdelT6);
		&DATA_WERT("Delivery_Tarif7_OBIS_2.8.7", $readingdelT7);
		&DATA_WERT("Delivery_Tarif8_OBIS_2.8.8", $readingdelT8);
		&DATA_WERT("Delivery_Tarif9_OBIS_2.8.9", $readingdelT9);
		&DATA_WERT("Delivery_CalculatedPower_OBIS_2.99.0", $powercalcdel);
		&DATA_WERT("Delivery_Power_OBIS_2.7.0", $power2);
		&DATA_WERT("Total_Power_OBIS_15.7.0", $power3);
		&DATA_WERT("Total_Power_OBIS_16.7.0", $power4);
		&DATA_WERT("Instantaneous_Voltage_L1_32.7.0", $volt1);
		&DATA_WERT("Instantaneous_Voltage_L2_52.7.0", $volt2);
		&DATA_WERT("Instantaneous_Voltage_L3_72.7.0", $volt3);
		&DATA_WERT("Instantaneous_Current_L1_31.7.0", $current1);
		&DATA_WERT("Instantaneous_Current_L2_51.7.0", $current2);
		&DATA_WERT("Instantaneous_Current_L3_71.7.0", $current3);
		&DATA_WERT("Equipment_Identifier_96.1.1", $eid);
		&DATA_WERT("Version_Information_96.1.4", $version);
		&DATA_WERT("Tarif_Indicator_Electricity_96.14.0", $currenttarif);
		&DATA_WERT("Breaker_State_Electricity_96.1.4", $breakerstate);
		&DATA_WERT("Text_Message_96.13.0", $messagetext);
		&DATA_WERT("Message_Code_96.13.1", $messagecode);
		&DATA_SCHLIESSEN($datereadable, $epoche_time_lox);

	}	else {

		&DATA_OEFFNEN();
				&DATA_WERT("Consumption_Total_OBIS_1.8.0", $readingconsT0);
		&DATA_WERT("Consumption_Tarif1_OBIS_1.8.1", $readingconsT1);
		&DATA_WERT("Consumption_Tarif2_OBIS_1.8.2", $readingconsT2);
		&DATA_WERT("Consumption_Tarif3_OBIS_1.8.3", $readingconsT3);
		&DATA_WERT("Consumption_Tarif4_OBIS_1.8.4", $readingconsT4);
		&DATA_WERT("Consumption_Tarif5_OBIS_1.8.5", $readingconsT5);
		&DATA_WERT("Consumption_Tarif6_OBIS_1.8.6", $readingconsT6);
		&DATA_WERT("Consumption_Tarif7_OBIS_1.8.7", $readingconsT7);
		&DATA_WERT("Consumption_Tarif8_OBIS_1.8.8", $readingconsT8);
		&DATA_WERT("Consumption_Tarif9_OBIS_1.8.9", $readingconsT9);
		&DATA_WERT("Consumption_CalculatedPower_OBIS_1.99.0", $powercalccons);
		&DATA_WERT("Consumption_Power_OBIS_1.7.0", $power1);
		&DATA_WERT("Consumption_Power_L1_OBIS_21.7.0", $power5);
		&DATA_WERT("Consumption_Power_L2_OBIS_41.7.0", $power6);
		&DATA_WERT("Consumption_Power_L3_OBIS_61.7.0", $power7);
		&DATA_WERT("Consumption_Power_L1_OBIS_36.7.0", $power8);
		&DATA_WERT("Consumption_Power_L2_OBIS_56.7.0", $power9);
		&DATA_WERT("Consumption_Power_L3_OBIS_76.7.0", $power10);
		&DATA_WERT("Delivery_Total_OBIS_2.8.0", $readingdelT0);
		&DATA_WERT("Delivery_Tarif1_OBIS_2.8.1", $readingdelT1);
		&DATA_WERT("Delivery_Tarif2_OBIS_2.8.2", $readingdelT2);
		&DATA_WERT("Delivery_Tarif3_OBIS_2.8.3", $readingdelT3);
		&DATA_WERT("Delivery_Tarif4_OBIS_2.8.4", $readingdelT4);
		&DATA_WERT("Delivery_Tarif5_OBIS_2.8.5", $readingdelT5);
		&DATA_WERT("Delivery_Tarif6_OBIS_2.8.6", $readingdelT6);
		&DATA_WERT("Delivery_Tarif7_OBIS_2.8.7", $readingdelT7);
		&DATA_WERT("Delivery_Tarif8_OBIS_2.8.8", $readingdelT8);
		&DATA_WERT("Delivery_Tarif9_OBIS_2.8.9", $readingdelT9);
		&DATA_WERT("Delivery_CalculatedPower_OBIS_2.99.0", $powercalcdel);
		&DATA_WERT("Delivery_Power_OBIS_2.7.0", $power2);
		&DATA_WERT("Total_Power_OBIS_15.7.0", $power3);
		&DATA_WERT("Total_Power_OBIS_16.7.0", $power4);
        &DATA_WERT("Delivery_Consumption_OBIS_C.5.0", $del_cons);
		&DATA_SCHLIESSEN($datereadable, $epoche_time_lox);

	}

	return();

}

################################
### SUB: Calculate Power
################################

sub CALCULATE_POWER
{

	our $reading = shift;
	our $direction = lc shift;
	&LOG ("Calculate average power for $direction.", "INFO");

	$reading = sprintf("%.3f", $reading);
	if ( !$reading ){
		&LOG ("No current meter reading. Calculation not possible,", "WARNING");
		return (0);
	}

	# Calculate power - the ISKRA MT174 doesn't provide power
	$now = time;
	if ( -e "/dev/shm/$psubfolder/$serial\.last$direction" ) {
		open(F,"</dev/shm/$psubfolder/$serial\.last$direction");
		@lines = <F>;
		foreach (@lines){
			s/[\n\r]//g;
			@fields  = split(/\|/);
			$lasttime = @fields[0];
			$lastreading = @fields[1];
		}
		close(F);
		if ( $reading < $lastreading ) {
			$lastreading = $reading;
		}
		$period = ($now - $lasttime) / 3600;
		$energy = $reading - $lastreading;
		$power = $energy / $period;
	} else {
		&LOG ("No last meter reading available. Calculation not possible,", "WARNING");
		system("touch /dev/shm/$psubfolder/$serial\.last$direction > /dev/null 2>&1");
		$period = 0;
		$energy = 0;
		$power = 0;
	}

	### Round
	$energy = sprintf("%.4f", $energy);
	$power = sprintf("%.4f", $power);
	$period = sprintf("%.4f", $period);

	### Debug output
	&LOG ("Last Reading: $lastreading. Saved before: $period hours. Consumption: $energy. Avg. Power: $power,", "INFO");

	### Zaehlerstand und Leistung speichern
	if ( $reading > 0 ) {
		open(F,">/dev/shm/$psubfolder/$serial\.last$direction");
			print F "$now|$reading\n";
		close(F);
	}

	return ($power);

}


################################
### SUB: Log
################################

sub LOG
{

	my $message	= shift; # http://wiki.selfhtml.org/wiki/Perl/Subroutinen
	my $type	= uc shift; # http://wiki.selfhtml.org/wiki/Perl/Subroutinen
	if ( !$type ) { $type = "INFO" };

	if ($verbose){
		print "$message\n";
	}

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
	open(F,">>/dev/shm/$psubfolder/$serial\.log");
		print F "$year-$mon-$mday $hour:$min:$sec <$type> $message\n";
	close (F);

  	return();

}

