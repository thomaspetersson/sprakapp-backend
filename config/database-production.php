<?php
class Database {
    private $host;
    private $db_name;
    private $username;
    private $password;
    private $port;
    public $conn;

    public function __construct() {
        // PRODUCTION VALUES FOR ONE.COM
        // Replace these with your actual database credentials from one.com
        $this->host = 'd90.se.mysql';  // e.g., d90.mysql.services.one.com
        $this->db_name = 'd90_sed90';  // Your database name on one.com
        $this->username = 'd90_sed90';  // Your database username
        $this->password = 'd3d407b65fb9d58cf6000f9662887f62';  // Your database password
        $this->port = '3306';
    }

    public function getConnection() {
        $this->conn = null;

        try {
            $this->conn = new PDO(
                "mysql:host=" . $this->host . 
                ";port=" . $this->port .
                ";dbname=" . $this->db_name,
                $this->username,
                $this->password
            );
            $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->conn->exec("set names utf8mb4");
        } catch(PDOException $exception) {
            error_log("Connection error: " . $exception->getMessage());
            throw new Exception("Database connection failed: " . $exception->getMessage());
        }

        return $this->conn;
    }
}
