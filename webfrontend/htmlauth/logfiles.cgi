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
my  @sm_files;
my  @sm_heads;
my  @sm_rows;
my  %sm_hash;
my  $sm_maintemplate;
my  $sm_template_title;
my  $sm_phrase;
my  $sm_helplink;
my  @sm_help;
my  $sm_helptext;
my  $sm_serial;

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
	system("ln -s /dev/shm/$sm_psubfolder  $sm_installfolder/log/plugins/$sm_psubfolder/shm > /dev/null 2>&1");
}

# Read all files in Logdir
opendir(DIR,"/dev/shm/$sm_psubfolder");
	@sm_files = readdir(DIR);
close DIR;
# Read all devices
my @sm_devices = split(/\n/,`ls /dev/serial/smartmeter/ 2>/dev/null`);
foreach (@sm_devices)
{
	my $sm_device 	= $_;
	$sm_device 	=~ s/([\n])//g;
	push (@sm_heads, $sm_device);
}

# Set parameters coming in - get over post
if ( $sm_cgi->url_param('lang') ) {
	$sm_lang = quotemeta( $sm_cgi->url_param('lang') );
}
elsif ( $sm_cgi->param('lang') ) {
	$sm_lang = quotemeta( $sm_cgi->param('lang') );
}

##########################################################################
# Initialize html templates
##########################################################################

# Header # At the moment not in HTML::Template format
#$headertemplate = HTML::Template->new(filename => "$sm_installfolder/templates/system/$sm_lang/header.html");

# Main
$sm_maintemplate = HTML::Template->new(
	filename => "$sm_installfolder/templates/plugins/$sm_psubfolder/multi/logfiles.html",
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

	# The page title read from language file + our name
	#$sm_template_title = $sm_phrase->param("TXT0000") . ": " . $sm_pname;

	# Print Template header
	&lbheader;

	# Read options and set them for template
	$sm_maintemplate->param( PSUBFOLDER	=> $sm_psubfolder );

	# Create a nested loop for HTML::Template - ARGGGGHH THIS is compicated!
	my $sm_i = 100;
	my $sm_ii = 100;
	my $sm_iii = 100;
	foreach (@sm_heads) {
		$sm_serial = $_;
		foreach (@sm_files) {
			if ( $_ =~ /$sm_serial/ ) {
				%{"hash".$sm_ii} = (
					LOGFILE	=> "$_",
				);
				push (@{"selfiles".$sm_i}, \%{"hash".$sm_ii});
			}
		$sm_ii++;
		}
		%{"hash1".$sm_i} = (
			SERIAL	=>	$sm_serial,
			FILES	=>	\@{"selfiles".$sm_i},
		);
		push (@sm_rows, \%{"hash1".$sm_i});
		$sm_i++;
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
  $sm_helplink = "http://www.loxwiki.eu/display/LOXBERRY/SML-eMon";
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
