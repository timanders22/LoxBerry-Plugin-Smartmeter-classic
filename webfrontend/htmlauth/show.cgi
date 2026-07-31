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

use CGI::Carp qw(fatalsToBrowser);
use CGI qw/:standard/;
use Config::Simple;
use File::HomeDir;
use Cwd 'abs_path';
use warnings;
use strict;
no strict "refs"; # we need it for template system and for contructs like ${"skalar".$i} in loops

##########################################################################
# Variables
##########################################################################
my  $sm_cgi = new CGI;
my  $sm_cfg;
my  $sm_plugin_cfg;
my  $sm_installfolder;
my  $sm_version;
my  $sm_home = File::HomeDir->my_home;
my  $sm_psubfolder;
my  $sm_pname;
my  $sm_serial;
my  @sm_lines;

##########################################################################
# Read Settings
##########################################################################

# Version of this script
$sm_version = "0.1";

# Figure out in which subfolder we are installed
$sm_psubfolder = abs_path($0);
$sm_psubfolder =~ s/(.*)\/(.*)\/(.*)$/$2/g;

# Start with HTML header
#print $sm_cgi->header(
#	type	=>	'text/html',
#	charset	=>	'utf-8',
#); 
print "Content-type: text/plain\n\n";

# Read general config
$sm_cfg	 	= new Config::Simple("$sm_home/config/system/general.cfg") or die $sm_cfg->error();
$sm_installfolder	= $sm_cfg->param("BASE.INSTALLFOLDER");

# Read plugin config
$sm_plugin_cfg 	= new Config::Simple("$sm_installfolder/config/plugins/$sm_psubfolder/smartmeter.cfg") or die $sm_plugin_cfg->error();
$sm_pname          = $sm_plugin_cfg->param("MAIN.SCRIPTNAME");

# Set parameters coming in - get over post
if ( $sm_cgi->url_param('serial') ) {
	$sm_serial = quotemeta( $sm_cgi->url_param('serial') );
}
elsif ( $sm_cgi->param('serial') ) {
	$sm_serial = quotemeta( $sm_cgi->param('serial') );
}

# Create temp folder if not already exist
if (!-d "/dev/shm/$sm_psubfolder") {
	system("mkdir -p /dev/shm/$sm_psubfolder > /dev/null 2>&1");
}
# Check for temporary log folder
if (!-e "$sm_installfolder/log/plugins/$sm_psubfolder/shm") {
	system("ln -s /dev/shm/$sm_psubfolder  $sm_installfolder/log/plugins/$sm_psubfolder/shm > /dev/null 2>&1");
}

##########################################################################
# Output
##########################################################################

# We need a serial
if ( !$sm_serial ) {

	print "No serial given.\n";

	exit;

}

# If no data exist, give dummy file
if ( !-e "/dev/shm/$sm_psubfolder/$sm_serial\.data" ) {

	print "$sm_serial:Last_Update:01.01.2009 00:00:00\n";
	print "$sm_serial:Last_UpdateLoxEpoche:1230764400\n";
	print "$sm_serial:Consumption_Total_OBIS_1.8.0:\n";
	print "$sm_serial:Consumption_Tarif1_OBIS_1.8.1:\n";
	print "$sm_serial:Consumption_Tarif2_OBIS_1.8.2:\n";
	print "$sm_serial:Consumption_Tarif3_OBIS_1.8.3:\n";
	print "$sm_serial:Consumption_Tarif4_OBIS_1.8.4:\n";
	print "$sm_serial:Consumption_Tarif5_OBIS_1.8.5:\n";
	print "$sm_serial:Consumption_Tarif6_OBIS_1.8.6:\n";
	print "$sm_serial:Consumption_Tarif7_OBIS_1.8.7:\n";
	print "$sm_serial:Consumption_Tarif8_OBIS_1.8.8:\n";
	print "$sm_serial:Consumption_Tarif9_OBIS_1.8.9:\n";
	print "$sm_serial:Consumption_CalculatedPower_OBIS_1.99.0:\n";
	print "$sm_serial:Consumption_Power_OBIS_1.7.0:\n";
	print "$sm_serial:Delivery_Total_OBIS_2.8.0:\n";
	print "$sm_serial:Delivery_Tarif1_OBIS_2.8.1:\n";
	print "$sm_serial:Delivery_Tarif2_OBIS_2.8.2:\n";
	print "$sm_serial:Delivery_Tarif3_OBIS_2.8.3:\n";
	print "$sm_serial:Delivery_Tarif4_OBIS_2.8.4:\n";
	print "$sm_serial:Delivery_Tarif5_OBIS_2.8.5:\n";
	print "$sm_serial:Delivery_Tarif6_OBIS_2.8.6:\n";
	print "$sm_serial:Delivery_Tarif7_OBIS_2.8.7:\n";
	print "$sm_serial:Delivery_Tarif8_OBIS_2.8.8:\n";
	print "$sm_serial:Delivery_Tarif9_OBIS_2.8.9:\n";
	print "$sm_serial:Delivery_CalculatedPower_OBIS_2.99.0:\n";
	print "$sm_serial:Delivery_Power_OBIS_2.7.0:\n";
	print "$sm_serial:Total_Power_OBIS_15.7.0:\n";
	print "$sm_serial:Total_Power_OBIS_16.7.0:\n";

	exit;

}

# Read data file
open(F,"</dev/shm/$sm_psubfolder/$sm_serial\.data");
	@sm_lines = <F>;
close(F);

foreach ( @sm_lines ) {
  print $_;
}

exit;
