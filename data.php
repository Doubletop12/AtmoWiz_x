<?php
	$error = null;

	require_once('mariadb.php');

	if(!isset($_SESSION['authenticated']) || $_SESSION['authenticated'] != true)
	{
		echo json_encode(array('status' => 403, 'error' => "You don't have access to this webpage"));
		exit;
	}

	if($error != null)
		reportError($error);

	$dataPoints1 = array();
	$dataPoints2 = array();
	$dataPoints3 = array();
	$dataPoints4 = array();

	$airconon = '';
	$uid = '';
	$ac = 'off';
	$currhumid = 0;
	$currtemp = 0.0;
	$currtime = "00:00";

	if(isset($_REQUEST['uid']) && $_REQUEST['uid'] != '')
		$uid = mysqli_real_escape_string($link, $_REQUEST['uid']);

	$query = "SELECT uid FROM devices";
	if($uid != '')
		$query .= " WHERE uid='$uid'";
	$query .= " LIMIT 1";
	$res = mysqli_query($link, $query);
	$uid = mysqli_fetch_assoc($res)['uid'];

	if(!isset($uid) || $uid == '')
	{
		$error = "Unable to get a UID, please check your database/configs and try again";
		reportError($error);
	}

	$query = "SELECT UNIX_TIMESTAMP(whentime) * 1000 as whentime,DATE_FORMAT(whentime, '%H:%i') as wttime,".
			"temperature,humidity,feelslike,rssi,airconon FROM sensibo ".
			"WHERE whentime >= now() - INTERVAL 36 HOUR AND uid='$uid' ORDER BY whentime ASC";
	$res = mysqli_query($link, $query);
	while($row = mysqli_fetch_assoc($res))
	{
		if($row['airconon'] != $airconon && $airconon != '')
		{
			$airconon = $row['airconon'];

			$ac = "off";
			if($airconon == 1)
				$ac = "on";

			if($ac == "on")
				$dataPoints1[] = array('x' => doubleval($row['whentime']), 'y' => floatval($row['temperature']), 'inindexLabel' => $ac, 'markerType' => 'cross',  'markerSize' =>  20,'markerColor' => 'green');
			else
				$dataPoints1[] = array('x' => doubleval($row['whentime']), 'y' => floatval($row['temperature']), 'inindexLabel' => $ac, 'markerType' => 'cross',  'markerSize' =>  20,'markerColor' => 'tomato');
		} else {
			$dataPoints1[] = array('x' => doubleval($row['whentime']), 'y' => floatval($row['temperature']));
			$airconon = $row['airconon'];
		}

		$dataPoints2[] = array('x' => doubleval($row['whentime']), 'y' => floatval($row['humidity']));
		$dataPoints3[] = array('x' => doubleval($row['whentime']), 'y' => floatval($row['feelslike']));
		$dataPoints4[] = array('x' => doubleval($row['whentime']), 'y' => floatval($row['rssi']));

		$currtemp = $row['temperature'];
		$currhumid = $row['humidity'];
		$currtime = $row['wttime'];
	}

	$negac = "on";
	if($ac == "on")
		$negac = "off";

	$line1 = "<b>".$currtime."</b> -- ".$currtemp."°C, ".$currhumid."%";

	if(isset($_SESSION['rw']) && $_SESSION['rw'] == true)
		$line1 .= " -- <a href='#' onClick='toggleAC(); return false;'>Turn AC $negac</a>";

	$lastdate = '';
	$commands = '';
	$commands .= "<li style='text-align:center;'><u><b>Current Conditions</b></u> -- <a href='graphs.php?logout=1'>Log Out</a></li>\n";

	$query = "SELECT uid,name FROM devices ORDER BY name";
	$res = mysqli_query($link, $query);
	if(mysqli_num_rows($res) > 1)
	{
		$commands .= "<li><label for='devices'>Choose a Device:</label>\n";
		$commands .= "<select name='devices' id='devices' onChange='changeAC(this.value); return false;'>\n";

		while($row = mysqli_fetch_assoc($res))
		{
			$commands .= "<option value='".$row['uid']."'";
			if($uid == $row['uid'])
				$commands .= " selected";
			$commands .= ">".$row['name']."</option>\n";
		}
		$commands .= "</select></li>\n";
	}

	$commands .= "<li>$line1</li>\n";

	$query = "SELECT *, DATE_FORMAT(whentime, '%a %d %b %Y') as wtdate, DATE_FORMAT(whentime, '%H:%i') as wttime FROM commands WHERE uid='$uid' ORDER BY whentime DESC";
	$dres = mysqli_query($link, $query);
	while($drow = mysqli_fetch_assoc($dres))
	{

		$date = $drow['wtdate'];
		if($date != $lastdate)
		{
			$commands .= "<li>&nbsp;</li>\n";
			$commands .= "<li style='text-align:center;'><u><b>$date</b></u></li>\n";
			$lastdate = $date;
		}

		$commands .= "<li><b>".$drow['wttime'].'</b> -- ';
		if($drow['reason'] == "ExternalIrCommand")
			$commands .= "Remote Control turned AC ";
		else if($drow['reason'] == "UserAPI")
			$commands .= "API turned AC ";
		else
			$commands .= "Unknown turned AC ";
		if($drow['airconon'])
			$commands .= "on";
		else
			$commands .= "off";
		$commands .= "</li>\n";
	}

	$data = array('uid' => $uid, 'dataPoints1' => $dataPoints1, 'dataPoints2' => $dataPoints2, 'dataPoints3' => $dataPoints3, 'dataPoints4' => $dataPoints4, 'commands' => $commands, 'currtime' => $currtime);
	echo json_encode(array('status' => 200, 'content' => $data));
