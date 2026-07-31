<?php
// LoxBerry smartmeter Plugin
// git@loxberry.woerstenfeld.de
// 02.03.2017 22:15:45
// ALPHA5
header('Content-Type: text/plain');
header('Content-Disposition: inline; filename="data"');
header('Expires: 0');
header('Cache-Control: must-revalidate');
header('Pragma: public');

$sm_psubdir  	=array_pop(array_filter(explode('/',pathinfo($_SERVER["SCRIPT_FILENAME"],PATHINFO_DIRNAME))));
$sm_directory	="/dev/shm/$sm_psubdir/";
$sm_dateitypen = array("data");

if (is_dir($sm_directory)) 
{
	$sm_handle			=opendir($sm_directory) or die("ERROR: $sm_directory not readable");
	while ($sm_file = readdir ($sm_handle)) 
	{
	 if ($sm_file != "." && $sm_file != ".." )
	  {
			$sm_file_data = pathinfo($sm_file);
		  if(in_array($sm_file_data['extension'],$sm_dateitypen))
		  {
			  if (file_exists($sm_directory.$sm_file))
			  {
			    $sm_f = @fopen($sm_directory.$sm_file, "r");
			    if ($sm_f !== false)
			    {
				    readfile($sm_directory.$sm_file);	
						fclose($sm_f);
			    }
			  }
			}
		}
	}
	closedir($sm_handle);
}
else
{
	die("ERROR: $sm_directory not readable");
}
echo "#EOF\n";
exit(0);
