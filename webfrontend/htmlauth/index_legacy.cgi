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
use Config::Crontab;
use LoxBerry::Log;
use File::HomeDir;
#use HTML::Entities;
use String::Escape qw( unquotemeta );
use Cwd 'abs_path';
use HTML::Template;
#use warnings;
#use strict;
#no strict "refs"; # we need it for template system and for contructs like ${"skalar".$sm_i} in loops

##########################################################################
# Variables
##########################################################################
my  $sm_cgi = new CGI;
my  $sm_cfg;
my  $sm_plugin_cfg;
my  $sm_lang;
my  $sm_installfolder;
my  $sm_languagefile;
my  $sm_version;
my  $sm_home = File::HomeDir->my_home;
my  $sm_psubfolder;
my  $sm_pname;
my  $sm_languagefileplugin;
my  %sm_TPhrases;
my  @sm_heads;
my  %sm_head;
my  @sm_rows;
my  %sm_hash;
my  $sm_maintemplate;
my  $sm_template_title;
my  $sm_phrase;
my  $sm_helplink;
my  @sm_help;
my  $sm_helptext;
my  $sm_saveformdata;
my  $sm_clearcache;
my  %sm_plugin_config;
my  $sm_name;
my  $sm_device;
my  $sm_serial;
my  $sm_crontabtmp = "$lbplogdir/crontab.temp";

##########################################################################
# Read crontab
##########################################################################

my $sm_crontab = new Config::Crontab;
$sm_crontab->system(1); ## Wichtig, damit der User im File berücksichtigt wird
$sm_crontab->read( -file => "$lbhomedir/system/cron/cron.d/$lbpplugindir" );


##########################################################################
# Read Settings
##########################################################################

# Version of this script
$sm_version = "2.0.0.1";

# Figure out in which subfolder we are installed
$sm_psubfolder = abs_path($0);
$sm_psubfolder =~ s/(.*)\/(.*)\/(.*)$/$2/g;

# Start with HTML header
#print $sm_cgi->header(
#	type	=>	'text/html',
#	charset	=>	'utf-8',
#); 
print "Content-type: text/html\n\n";

# Read general config
$sm_cfg	 	= new Config::Simple("$sm_home/config/system/general.cfg") or die $sm_cfg->error();
$sm_installfolder	= $sm_cfg->param("BASE.INSTALLFOLDER");
$sm_lang		= $sm_cfg->param("BASE.LANG");

# Read plugin config
$sm_plugin_cfg 	= new Config::Simple("$sm_installfolder/config/plugins/$sm_psubfolder/smartmeter.cfg") or die $sm_plugin_cfg->error();
$sm_pname          = $sm_plugin_cfg->param("MAIN.SCRIPTNAME");

# Create temp folder if not already exist
if (!-d "/dev/shm/$sm_psubfolder") {
	system("mkdir -p /dev/shm/$sm_psubfolder > /dev/null 2>&1");
}
# Check for temporary log folder
if (!-e "$sm_installfolder/log/plugins/$sm_psubfolder/shm") {
	system("ln -s /dev/shm/$sm_psubfolder $sm_installfolder/log/plugins/$sm_psubfolder/shm > /dev/null 2>&1");
}

# Detect which IR Heads are connected
my @sm_heads = split(/\n/,`ls /dev/serial/smartmeter/*`);

# Save a config set if it not already exists
foreach (@sm_heads) {
	$sm_serial = $_;
	$sm_serial =~ s%/dev/serial/smartmeter/%%g;
	if ( !$sm_plugin_cfg->param("$sm_serial.DEVICE") ) {
		$sm_plugin_cfg->param("$sm_serial.NAME", "$sm_serial");
		$sm_plugin_cfg->param("$sm_serial.SERIAL", "$sm_serial");
		$sm_plugin_cfg->param("$sm_serial.DEVICE", "$_");
		$sm_plugin_cfg->param("$sm_serial.METER", "0");
		$sm_plugin_cfg->param("$sm_serial.PROTOCOL", "");
		$sm_plugin_cfg->param("$sm_serial.STARTBAUDRATE", "");
		$sm_plugin_cfg->param("$sm_serial.BAUDRATE", "");
		$sm_plugin_cfg->param("$sm_serial.TIMEOUT", "");
		$sm_plugin_cfg->param("$sm_serial.DELAY", "");
		$sm_plugin_cfg->param("$sm_serial.HANDSHAKE", "");
		$sm_plugin_cfg->param("$sm_serial.DATABITS", "");
		$sm_plugin_cfg->param("$sm_serial.STOPBITS", "");
		$sm_plugin_cfg->param("$sm_serial.PARITY", "");
        $sm_plugin_cfg->param("$sm_serial.CRC", "");
	}
}
$sm_plugin_cfg->save;

# Set parameters coming in - get over post
if ( $sm_cgi->url_param('lang') ) {
	$sm_lang = quotemeta( $sm_cgi->url_param('lang') );
}
elsif ( $sm_cgi->param('lang') ) {
	$sm_lang = quotemeta( $sm_cgi->param('lang') );
}
if ( $sm_cgi->url_param('saveformdata') ) {
	$sm_saveformdata = quotemeta( $sm_cgi->url_param('saveformdata') );
}
elsif ( $sm_cgi->param('saveformdata') ) {
	$sm_saveformdata = quotemeta( $sm_cgi->param('saveformdata') );
}
if ( $sm_cgi->url_param('clearcache') ) {
	$sm_clearcache = quotemeta( $sm_cgi->url_param('clearcache') );
}
elsif ( $sm_cgi->param('clearcache') ) {
	$sm_clearcache = quotemeta( $sm_cgi->param('clearcache') );
}

##########################################################################
# Initialize html templates
##########################################################################

# Header # At the moment not in HTML::Template format
#$headertemplate = HTML::Template->new(filename => "$sm_installfolder/templates/system/$sm_lang/header.html");

# Main
$sm_maintemplate = HTML::Template->new(
	filename => "$sm_installfolder/templates/plugins/$sm_psubfolder/multi/main.html",
	global_vars => 1,
	loop_context_vars => 1,
	die_on_bad_params => 0,
	associate => $sm_cgi,
);

# Footer # At the moment not in HTML::Template format
#$footertemplate = HTML::Template->new(filename => "$sm_installfolder/templates/system/$sm_lang/footer.html");

##########################################################################
# Translations
##########################################################################

# Init Language
# Clean up lang variable
$sm_lang         =~ tr/a-z//cd;
$sm_lang         = substr($sm_lang,0,2);

# Read Plugin transations
# Read English language as default
# Missing phrases in foreign language will fall back to English
$sm_languagefileplugin 	= "$sm_installfolder/templates/plugins/$sm_psubfolder/en/language.txt";
Config::Simple->import_from($sm_languagefileplugin, \%sm_TPhrases);

# If there's no language phrases file for choosed language, use english as default
if (!-e "$sm_installfolder/templates/system/$sm_lang/language.dat")
{
  $sm_lang = "en";
}

# Read foreign language if exists and not English
$sm_languagefileplugin = "$sm_installfolder/templates/plugins/$sm_psubfolder/$sm_lang/language.txt";
if ((-e $sm_languagefileplugin) and ($sm_lang ne 'en')) {
	# Now overwrite phrase variables with user language
	Config::Simple->import_from($sm_languagefileplugin, \%sm_TPhrases);
}

# Parse Language phrases to html templates
while (my ($sm_name, $sm_value) = each %sm_TPhrases){
	$sm_maintemplate->param("T::$sm_name" => $sm_value);
	#$headertemplate->param("T::$sm_name" => $sm_value);
	#$footertemplate->param("T::$sm_name" => $sm_value);
}

##########################################################################
# Main program
##########################################################################

&form;

exit;

#####################################################
# 
# Subroutines
#
#####################################################

#####################################################
# Form-Sub
#####################################################

sub form 
{

	# Clear Cache
	if ( $sm_clearcache ) {
		system("rm /dev/shm/$sm_psubfolder/* > /dev/null 2>&1");
	}

	# If the form was saved, update config file
	if ( $sm_saveformdata ) {
		$sm_plugin_cfg->param( "MAIN.READ", $sm_cgi->param('read') );
		$sm_plugin_cfg->param( "MAIN.CRON", $sm_cgi->param('cron') );
		# MQTT wird ausschliesslich im Reiter "MQTT" gespeichert (index.cgi).
		# Wuerde hier weiter geschrieben, loeschte jedes Speichern auf dieser
		# Seite die Einstellung, weil die Felder hier nur noch anzeigen.
		$sm_plugin_cfg->param( "MAIN.SENDUDP", $sm_cgi->param('sendudp') );
		$sm_plugin_cfg->param( "MAIN.UDPPORT", $sm_cgi->param('udpport') );
		foreach (@sm_heads) {
			$sm_serial = $_;
			$sm_serial =~ s%/dev/serial/smartmeter/%%g;
			$sm_plugin_cfg->param("$sm_serial.NAME", $sm_cgi->param("$sm_serial\_name") );
			$sm_plugin_cfg->param("$sm_serial.METER", $sm_cgi->param("$sm_serial\_meter") );
			if ( $sm_cgi->param("$sm_serial\_meter") eq "manual" ) {
				$sm_plugin_cfg->param("$sm_serial.PROTOCOL", $sm_cgi->param("$sm_serial\_protocol") );
				$sm_plugin_cfg->param("$sm_serial.STARTBAUDRATE", $sm_cgi->param("$sm_serial\_startbaudrate") );
				$sm_plugin_cfg->param("$sm_serial.BAUDRATE", $sm_cgi->param("$sm_serial\_baudrate") );
				$sm_plugin_cfg->param("$sm_serial.TIMEOUT", $sm_cgi->param("$sm_serial\_timeout") );
				$sm_plugin_cfg->param("$sm_serial.DELAY", $sm_cgi->param("$sm_serial\_delay") );
				$sm_plugin_cfg->param("$sm_serial.HANDSHAKE", $sm_cgi->param("$sm_serial\_handshake") );
				$sm_plugin_cfg->param("$sm_serial.DATABITS", $sm_cgi->param("$sm_serial\_databits") );
				$sm_plugin_cfg->param("$sm_serial.STOPBITS", $sm_cgi->param("$sm_serial\_stopbits") );
				$sm_plugin_cfg->param("$sm_serial.PARITY", $sm_cgi->param("$sm_serial\_parity") );
				$sm_plugin_cfg->param("$sm_serial.CRC", $sm_cgi->param("$sm_serial\_crc") );
			} else {
				$sm_plugin_cfg->param("$sm_serial.PROTOCOL", "");
				$sm_plugin_cfg->param("$sm_serial.STARTBAUDRATE", "");
				$sm_plugin_cfg->param("$sm_serial.BAUDRATE", "");
				$sm_plugin_cfg->param("$sm_serial.TIMEOUT", "");
				$sm_plugin_cfg->param("$sm_serial.DELAY", "");
				$sm_plugin_cfg->param("$sm_serial.HANDSHAKE", "");
				$sm_plugin_cfg->param("$sm_serial.DATABITS", "");
				$sm_plugin_cfg->param("$sm_serial.STOPBITS", "");
				$sm_plugin_cfg->param("$sm_serial.PARITY", "");
				$sm_plugin_cfg->param("$sm_serial.CRC", "");
			}
		}
		$sm_plugin_cfg->save;

		# Create Cronjob
		if ( $sm_cgi->param('read') eq "1" ) 
		{
			if ($sm_cgi->param('cron') eq "M") 
			{
				# Check if Script already running?
				if (!scalar(grep{/sm_logger.pl/} `ps aux`))
				{	
					system ("perl $sm_installfolder/bin/plugins/$sm_psubfolder/fetch.pl >/dev/null 2>&1 &");
				}
				system ("ln -s $sm_installfolder/bin/plugins/$sm_psubfolder/reboot_cron_runner.sh $sm_installfolder/system/cron/cron.reboot/$sm_pname");
				unlink ("$sm_installfolder/system/cron/cron.01min/$sm_pname");
				unlink ("$sm_installfolder/system/cron/cron.03min/$sm_pname");
				unlink ("$sm_installfolder/system/cron/cron.05min/$sm_pname");
				unlink ("$sm_installfolder/system/cron/cron.10min/$sm_pname");
				unlink ("$sm_installfolder/system/cron/cron.15min/$sm_pname");
				unlink ("$sm_installfolder/system/cron/cron.30min/$sm_pname");
				unlink ("$sm_installfolder/system/cron/cron.hourly/$sm_pname");
			}
			if ($sm_cgi->param('cron') eq "1") 
			{
				system ("ln -s $sm_installfolder/bin/plugins/$sm_psubfolder/fetch.pl $sm_installfolder/system/cron/cron.01min/$sm_pname");
				unlink ("$sm_installfolder/system/cron/cron.03min/$sm_pname");
				unlink ("$sm_installfolder/system/cron/cron.05min/$sm_pname");
				unlink ("$sm_installfolder/system/cron/cron.10min/$sm_pname");
				unlink ("$sm_installfolder/system/cron/cron.15min/$sm_pname");
				unlink ("$sm_installfolder/system/cron/cron.30min/$sm_pname");
				unlink ("$sm_installfolder/system/cron/cron.hourly/$sm_pname");
				unlink ("$sm_installfolder/system/cron/cron.reboot/$sm_pname");
			}
			if ($sm_cgi->param('cron') eq "3") 
			{
				system ("ln -s $sm_installfolder/bin/plugins/$sm_psubfolder/fetch.pl $sm_installfolder/system/cron/cron.03min/$sm_pname");
				unlink ("$sm_installfolder/system/cron/cron.01min/$sm_pname");
				unlink ("$sm_installfolder/system/cron/cron.05min/$sm_pname");
				unlink ("$sm_installfolder/system/cron/cron.10min/$sm_pname");
				unlink ("$sm_installfolder/system/cron/cron.15min/$sm_pname");
				unlink ("$sm_installfolder/system/cron/cron.30min/$sm_pname");
				unlink ("$sm_installfolder/system/cron/cron.hourly/$sm_pname");
				unlink ("$sm_installfolder/system/cron/cron.reboot/$sm_pname");
			}
			if ($sm_cgi->param('cron') eq "5") 
			{
				system ("ln -s $sm_installfolder/bin/plugins/$sm_psubfolder/fetch.pl $sm_installfolder/system/cron/cron.05min/$sm_pname");
				unlink ("$sm_installfolder/system/cron/cron.01min/$sm_pname");
				unlink ("$sm_installfolder/system/cron/cron.03min/$sm_pname");
				unlink ("$sm_installfolder/system/cron/cron.10min/$sm_pname");
				unlink ("$sm_installfolder/system/cron/cron.15min/$sm_pname");
				unlink ("$sm_installfolder/system/cron/cron.30min/$sm_pname");
				unlink ("$sm_installfolder/system/cron/cron.hourly/$sm_pname");
				unlink ("$sm_installfolder/system/cron/cron.reboot/$sm_pname");
			}
			if ($sm_cgi->param('cron') eq "10") 
			{
				system ("ln -s $sm_installfolder/bin/plugins/$sm_psubfolder/fetch.pl $sm_installfolder/system/cron/cron.10min/$sm_pname");
				unlink ("$sm_installfolder/system/cron/cron.1min/$sm_pname");
				unlink ("$sm_installfolder/system/cron/cron.3min/$sm_pname");
				unlink ("$sm_installfolder/system/cron/cron.5min/$sm_pname");
				unlink ("$sm_installfolder/system/cron/cron.15min/$sm_pname");
				unlink ("$sm_installfolder/system/cron/cron.30min/$sm_pname");
				unlink ("$sm_installfolder/system/cron/cron.hourly/$sm_pname");
				unlink ("$sm_installfolder/system/cron/cron.reboot/$sm_pname");
			}
			if ($sm_cgi->param('cron') eq "15") 
			{
				system ("ln -s $sm_installfolder/bin/plugins/$sm_psubfolder/fetch.pl $sm_installfolder/system/cron/cron.15min/$sm_pname");
				unlink ("$sm_installfolder/system/cron/cron.01min/$sm_pname");
				unlink ("$sm_installfolder/system/cron/cron.03min/$sm_pname");
				unlink ("$sm_installfolder/system/cron/cron.05min/$sm_pname");
				unlink ("$sm_installfolder/system/cron/cron.10min/$sm_pname");
				unlink ("$sm_installfolder/system/cron/cron.30min/$sm_pname");
				unlink ("$sm_installfolder/system/cron/cron.hourly/$sm_pname");
				unlink ("$sm_installfolder/system/cron/cron.reboot/$sm_pname");
			}
			if ($sm_cgi->param('cron') eq "30") 
			{
				system ("ln -s $sm_installfolder/bin/plugins/$sm_psubfolder/fetch.pl $sm_installfolder/system/cron/cron.30min/$sm_pname");
				unlink ("$sm_installfolder/system/cron/cron.01min/$sm_pname");
				unlink ("$sm_installfolder/system/cron/cron.03min/$sm_pname");
				unlink ("$sm_installfolder/system/cron/cron.05min/$sm_pname");
				unlink ("$sm_installfolder/system/cron/cron.10min/$sm_pname");
				unlink ("$sm_installfolder/system/cron/cron.15min/$sm_pname");
				unlink ("$sm_installfolder/system/cron/cron.hourly/$sm_pname");
				unlink ("$sm_installfolder/system/cron/cron.reboot/$sm_pname");
			}
			if ($sm_cgi->param('cron') eq "60") 
			{
				system ("ln -s $sm_installfolder/bin/plugins/$sm_psubfolder/fetch.pl $sm_installfolder/system/cron/cron.hourly/$sm_pname");
				unlink ("$sm_installfolder/system/cron/cron.01min/$sm_pname");
				unlink ("$sm_installfolder/system/cron/cron.03min/$sm_pname");
				unlink ("$sm_installfolder/system/cron/cron.05min/$sm_pname");
				unlink ("$sm_installfolder/system/cron/cron.10min/$sm_pname");
				unlink ("$sm_installfolder/system/cron/cron.15min/$sm_pname");
				unlink ("$sm_installfolder/system/cron/cron.30min/$sm_pname");
				unlink ("$sm_installfolder/system/cron/cron.reboot/$sm_pname");
			}
			  
		} else {
			unlink ("$sm_installfolder/system/cron/cron.01min/$sm_pname");
			unlink ("$sm_installfolder/system/cron/cron.03min/$sm_pname");
			unlink ("$sm_installfolder/system/cron/cron.05min/$sm_pname");
			unlink ("$sm_installfolder/system/cron/cron.10min/$sm_pname");
			unlink ("$sm_installfolder/system/cron/cron.15min/$sm_pname");
			unlink ("$sm_installfolder/system/cron/cron.30min/$sm_pname");
			unlink ("$sm_installfolder/system/cron/cron.hourly/$sm_pname");
            unlink ("$sm_installfolder/system/cron/cron.reboot/$sm_pname");
		}

	}
	
	# The page title read from language file + our name
	#$sm_template_title = $sm_phrase->param("TXT0000") . ": " . $sm_pname;
	
	# Navbar
	our %sm_navbar;

	$sm_navbar{10}{Name} = "Test";
	$sm_navbar{10}{URL} = 'index.cgi?form=owfs';
	$sm_navbar{10}{active} = 1 if $q->{form} eq "owfs";
	
	$sm_navbar{20}{Name} = "Test2";
	$sm_navbar{20}{URL} = 'index.cgi?form=devices';
	$sm_navbar{20}{active} = 1 if $q->{form} eq "devices";
	
	$sm_navbar{30}{Name} = "$L{'COMMON.LABEL_MQTT'}";
	$sm_navbar{30}{URL} = 'index.cgi?form=mqtt';
	$sm_navbar{30}{active} = 1 if $q->{form} eq "mqtt";
	
	$sm_navbar{98}{Name} = "$L{'COMMON.LABEL_LOG'}";
	$sm_navbar{98}{URL} = 'index.cgi?form=log';
	$sm_navbar{98}{active} = 1 if $q->{form} eq "log";

	$sm_navbar{99}{Name} = "$L{'COMMON.LABEL_CREDITS'}";
	$sm_navbar{99}{URL} = 'index.cgi?form=credits';
	$sm_navbar{99}{active} = 1 if $q->{form} eq "credits";

	# Print Template header
	&lbheader;

	# Read options and set them for template
	$sm_maintemplate->param( PSUBFOLDER	=> $sm_psubfolder );
	$sm_maintemplate->param( HOST 		=> $ENV{HTTP_HOST} );
	$sm_maintemplate->param( LOGINNAME		=> $ENV{REMOTE_USER} );
	$sm_maintemplate->param( READ 		=> $sm_plugin_cfg->param("MAIN.READ") );
	$sm_maintemplate->param( CRON 		=> $sm_plugin_cfg->param("MAIN.CRON") );
	my $sm_sendmqtt = $sm_plugin_cfg->param("MAIN.SENDMQTT");
	$sm_sendmqtt = 1 if !defined $sm_sendmqtt;
	$sm_maintemplate->param( SENDMQTT 		=> $sm_sendmqtt );
	$sm_maintemplate->param( MQTTTOPIC 		=> $sm_plugin_cfg->param("MAIN.MQTTTOPIC") || "smartmeter" );
	$sm_maintemplate->param( SENDUDP 		=> $sm_plugin_cfg->param("MAIN.SENDUDP") );
	$sm_maintemplate->param( UDPPORT 		=> $sm_plugin_cfg->param("MAIN.UDPPORT") );

  	# Read the config for all found heads
	my $sm_i = 0;
	foreach (@sm_heads) {
		$sm_serial = $_;
		$sm_serial =~ s%/dev/serial/smartmeter/%%g;
		if ( $sm_plugin_cfg->param("$sm_serial.DEVICE") ) {
			%{"hash".$sm_i} = (
			NAME 		=>	$sm_plugin_cfg->param("$sm_serial.NAME"),
			SERIAL		=>	$sm_plugin_cfg->param("$sm_serial.SERIAL"),
			DEVICE		=>	$sm_plugin_cfg->param("$sm_serial.DEVICE"),
			METER		=>	$sm_plugin_cfg->param("$sm_serial.METER"),
			PROTOCOL	=>	$sm_plugin_cfg->param("$sm_serial.PROTOCOL"),
			STARTBAUDRATE	=>	$sm_plugin_cfg->param("$sm_serial.STARTBAUDRATE"),
			BAUDRATE	=>	$sm_plugin_cfg->param("$sm_serial.BAUDRATE"),
			TIMEOUT		=>	$sm_plugin_cfg->param("$sm_serial.TIMEOUT"),
			DELAY		=>	$sm_plugin_cfg->param("$sm_serial.DELAY"),
			HANDSHAKE	=>	$sm_plugin_cfg->param("$sm_serial.HANDSHAKE"),
			DATABITS	=>	$sm_plugin_cfg->param("$sm_serial.DATABITS"),
			STOPBITS	=>	$sm_plugin_cfg->param("$sm_serial.STOPBITS"),
			PARITY		=>	$sm_plugin_cfg->param("$sm_serial.PARITY"),
			CRC		    =>	$sm_plugin_cfg->param("$sm_serial.CRC"),
			);
			push (@sm_rows, \%{"hash".$sm_i});
			$sm_i++;
		} 
	}
	$sm_maintemplate->param( ROWS => \@sm_rows );

	# Print Template
	print $sm_maintemplate->output;

	# Parse page footer		
	&lbfooter;

	exit;

}

#####################################################
# Page-Header-Sub
#####################################################

sub lbheader 
{
	 # Create Help page
  $sm_helplink = "https://www.loxwiki.eu/x/mA-L";
  open(F,"$sm_installfolder/templates/plugins/$sm_psubfolder/multi/help.html") || die "Missing template plugins/$sm_psubfolder/$sm_lang/help.html";
    @sm_help = <F>;
    foreach (@sm_help)
    {
      $_ =~ s/<!--\$sm_psubfolder-->/$sm_psubfolder/g;
      s/[\n\r]/ /g;
      $_ =~ s/<!--\$(.*?)-->/${$1}/g;
      $sm_helptext = $sm_helptext . $_;
    }
  close(F);
  open(F,"$sm_installfolder/templates/system/$sm_lang/header.html") || die "Missing template system/$sm_lang/header.html";
    while (<F>) 
    {
      $_ =~ s/<!--\$(.*?)-->/${$1}/g;
      print $_;
    }
  close(F);
}

#####################################################
# Footer
#####################################################

sub lbfooter 
{
  open(F,"$sm_installfolder/templates/system/$sm_lang/footer.html") || die "Missing template system/$sm_lang/footer.html";
    while (<F>) 
    {
      $_ =~ s/<!--\$(.*?)-->/${$1}/g;
      print $_;
    }
  close(F);
}
