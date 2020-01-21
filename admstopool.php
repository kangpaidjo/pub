<?php
echo "Start at " . date("Y-m-d H:i:s", time()) . "\r\n";

// Start Config

$db_host = "127.0.0.1";
$db_port = 83306;
$db_name = "adms_db";
$db_user = "root";
$db_password = "password"; # or empty

$api_pool_endpoint = "http://192.168.20.223/to_pool_api.php";
$api_username = "user";
$api_password = "password";

$maxBatch = 500;
$minuteBetweenCrons = 15;
// End Config

$cronjobDelta = ($minuteBetweenCrons + 5) * 60;

class Database
{
    public $conn;

    public function getConnection($host, $db_name, $username, $password, $port)
    {

        $this->conn = null;

        $this->conn = mysqli_connect($host, $username, $password, $db_name, $port);
        if (mysqli_connect_errno()) {
            exit();
        }

        return $this->conn;
    }
}

class AdmsData
{

    // database connection and table name
    private $conn;
    private $maxBatch;
    private $table_name = "checkinout";
    private $table_user = "userinfo";
    private $cronjobDelta;

    // constructor with $db as database connection
    public function __construct($db, $maxBatch, $minuteBetweenCrons)
    {
        $this->maxBatch = $maxBatch;
        $this->conn = $db;
        $this->cronjobDelta = ($minuteBetweenCrons + 5) * 60;
    }

    public function getAdmsData($api_pool_endpoint, $api_username, $api_password)
    {
        $now = time();
        $startTime = date("Y-m-d H:i:s", ($now - $this->cronjobDelta));
        $endTime = date("Y-m-d H:i:s", ($now + (5 * 60)));
        $queryString = "SELECT B.badgenumber, A.checktime, A.checktype, A.verifycode, A.sensorid, A.SN, C.IPAddress, C.Alias " .
        "FROM " . $this->table_name . " A, " . $this->table_user . " B, iclock C " .
            "WHERE A.userid = B.userid AND A.SN = C.SN " .
            "AND A.checktime BETWEEN '$startTime' AND '$endTime'";

        if ($result = mysqli_query($this->conn, $queryString)) {
            printf("Select returned %d rows.\n", mysqli_num_rows($result));

            $rows = [];
            $i = 1;
            while ($fetched = mysqli_fetch_assoc($result)) {
                $rows[] = array(
                    "Badgenumber" => $fetched["badgenumber"],
                    "CHECKTIME" => $fetched["checktime"],
                    "CHECKTYPE" => $fetched["checktype"],
                    "VERIFYCODE" => $fetched["verifycode"],
                    "SENSORID" => $fetched["sensorid"],
                    "sn" => $fetched["SN"],
                    "IP" => $fetched["IPAddress"],
                    "MachineAlias" => $fetched["Alias"],
                );

                if ($i % $this->maxBatch == 0) {
                    $ch = curl_init($api_pool_endpoint);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type:application/json', 'Authorization: Basic ' . base64_encode($api_username . $api_password)));
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(array("rows" => $rows)));
                    $response = json_decode(curl_exec($ch), true);
                    curl_close($ch);
                    if ($response["status_code"] == "00" && $response["status_desc"] == "SUKSES") {
                        echo "Sent " . $this->maxBatch . " data \r\n";
                    } else {
                        die('Could not send data');
                    }
                    $rows = [];
                }
                $i++;
            }
            $ch = curl_init($api_pool_endpoint);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type:application/json', 'Authorization: Basic ' . base64_encode($api_username . $api_password)));
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(array("rows" => $rows)));
            $response = json_decode(curl_exec($ch), true);
            curl_close($ch);
            if ($response["status_code"] == "00" && $response["status_desc"] == "SUKSES") {
                echo "FINISHED";
            } else {
                die('Could not send data');
            }
        }
    }

}

$database = new Database();
$db = $database->getConnection($db_host, $db_name, $db_user, $db_password, $db_port);

$admsData = new AdmsData($db, $maxBatch, $minuteBetweenCrons);
$admsData->getAdmsData($api_pool_endpoint, $api_username, $api_password);
