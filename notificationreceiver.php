<?php
	//Server-dependent variables
	include 'SecretData.php';
	
	$sqlink = selectBattleshipsDB(connectSQL());
	recordAccess($sqlink);
	
	try
    {
		$message_type = $_POST["message_type"];
		if ($message_type == "outgoing")
		{
            $message_id = $_POST["message_id"];
            $mobile_number = $_POST["mobile_number"];
            $shortcode = $_POST["shortcode"];
            $status = $_POST["status"];
            $timestamp = $_POST["timestamp"];
            $credits_cost = $_POST["credits_cost"];
			recordChikkaNotification($sqlink, $message_id, $mobile_number, $shortcode, $status, $timestamp, $credits_cost);
            echo "Accepted";
            exit(0);
        }
		else
		{
			throw new Exception('message_type invalid');
		}
	}
	catch (Exception $e)
	{
		echo "Error";
		exit(0);
	}
	
	function recordChikkaNotification($link, $message_id, $mobile_number, $shortcode, $status, $timestamp, $credits_cost) //test method
	{
		$sqlcommand = 	"CREATE TABLE IF NOT EXISTS Notifications
						(
							id INT(6) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
							messageid VARCHAR(32),
							mobilenumber VARCHAR(12),
							shortcode VARCHAR(11),
							status VARCHAR(8),
							timestamp VARCHAR(16),
							creditscost VARCHAR(10)
						)";
		if(mysqli_query($link, $sqlcommand))
		{
			echo 'Notifications database created/accessed. <br>';
		}
		else
		{
			echo 'Notifications database WAS NOT created/accessed. <br>';
		}
		$sqlcommand = 	"INSERT INTO Notifications(messageid, mobilenumber, shortcode, status, timestamp, creditscost) 
						VALUES('$message_id', '$mobile_number', '$shortcode', '$status', '$timestamp', '$credits_cost')";
		if(mysqli_query($link, $sqlcommand))
		{
			echo 'Notification recorded into database. <br>';
		}
		else
		{
			die('Notification was NOT recorded into database: ' . mysqli_error($link));
		}
	}
	function recordAccess($link) //test method
	{
		$sqlcommand = 	"CREATE TABLE IF NOT EXISTS Access
						(
							id INT(6) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
							accesstime VARCHAR(16),
							accesspage VARCHAR(32)
						)";
		if(mysqli_query($link, $sqlcommand))
		{
			echo 'Access database created/accessed. <br>';
		}
		else
		{
			echo 'Access database WAS NOT created/accessed. <br>';
		}
		$timeaccessed = time();
		$sqlcommand = 	"INSERT INTO Access(accesstime, accesspage) 
						VALUES('$timeaccessed', 'notificationsreceiver')";
		if(mysqli_query($link, $sqlcommand))
		{
			echo 'Access recorded into database. <br>';
		}
		else
		{
			die('Access was NOT recorded into database: ' . mysqli_error($link));
		}
	}
	function connectSQL()
	{
		$link = mysqli_connect(SQLHOST,SQLUSER,SQLPASS); 
		if (!$link) 
		{ 
			die('Could not connect to MySQL: ' . mysqli_error()); 
		} 
		echo 'Connection OK <br>'; 
		return $link;
	}
	function createBattleshipsDB($link)
	{
		$sqlcommand = "CREATE DATABASE IF NOT EXISTS ".SQLDB;
		if (mysqli_query($link, $sqlcommand)) 
		{
			echo "Database created successfully <br>";
		} 
		else 
		{
			echo "Error creating database: " . mysqli_error($link);
		}
	}
	function selectBattleshipsDB($link)
	{
		createBattleshipsDB($link);
		$link = mysqli_connect(SQLHOST, SQLUSER, SQLPASS, SQLDB); 
			if (!$link) 
			{ 
				die('Could not connect to battleships: ' . mysqli_error()); 
			} 
		echo 'Database OK <br>'; 
		return $link;
	}
?>