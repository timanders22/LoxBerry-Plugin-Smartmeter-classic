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
my  $sm_lang;
my  $sm_installfolder;
my  $sm_version;
my  $sm_home = File::HomeDir->my_home;
my  $sm_psubfolder;
my  $sm_pname;
my  $sm_serial;
my  $sm_logfile;
my  $sm_pid;

##########################################################################
# Read Settings
##########################################################################

# Version of this script
$sm_version = "0.2";

# Figure out in which subfolder we are installed
$sm_psubfolder = abs_path($0);
$sm_psubfolder =~ s/(.*)\/(.*)\/(.*)$/$2/g;

# Read general config
$sm_cfg	 	= new Config::Simple("$sm_home/config/system/general.cfg") or die $sm_cfg->error();
$sm_installfolder	= $sm_cfg->param("BASE.INSTALLFOLDER");
$sm_lang		= $sm_cfg->param("BASE.LANG");

##########################################################################
# Main program
##########################################################################

# Create temp folder if not already exist
if (!-d "/dev/shm/$sm_psubfolder") {
	system("mkdir -p /dev/shm/$sm_psubfolder > /dev/null 2>&1");
}
# Check for temporary log folder
if (!-e "$sm_installfolder/log/plugins/$sm_psubfolder/shm") {
	system("ln -s /dev/shm/$sm_psubfolder  $sm_installfolder/log/plugins/$sm_psubfolder/shm > /dev/null 2>&1");
}
# Create Logfile
$sm_logfile = "/dev/shm/$sm_psubfolder/fetch_manually.log";
system("rm /dev/shm/$sm_psubfolder/$sm_logfile");
system("touch /dev/shm/$sm_psubfolder/$sm_logfile");

# Redirect to Logviewer
print redirect(-url=>"/admin/system/tools/logfile.cgi?logfile=plugins/$sm_psubfolder/shm/fetch_manually.log&header=html&format=template");

# Without the following workaround
# the script cannot be executed as
# background process via CGI
$sm_pid = fork();
die "Fork failed: $!" if !defined $sm_pid;


if ($sm_pid == 0) {
  # do this in the child
  open STDIN, "</dev/null";
  open STDOUT, ">$sm_logfile";
  #open STDERR, ">$sm_logfile";
  open STDERR, ">/dev/null";

  # Trigger fetch
  system("$sm_installfolder/bin/plugins/$sm_psubfolder/fetch.pl --verbose --force");
}

exit;
