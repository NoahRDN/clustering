<?php
class DBSessionHandler implements SessionHandlerInterface {
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    public function open($savePath, $sessionName) {
        return true;
    }

    public function close() {
        return true;
    }

    public function read($id) {
        $stmt = $this->pdo->prepare("SELECT data FROM sessions WHERE id = :id");
        $stmt->execute(['id' => $id]);
        $session = $stmt->fetchColumn();
        return $session ? $session : '';
    }

    public function write($id, $data) {
        $stmt = $this->pdo->prepare("
            INSERT INTO sessions (id, data) VALUES (:id, :data)
            ON DUPLICATE KEY UPDATE data = :data, last_access = CURRENT_TIMESTAMP
        ");
        return $stmt->execute(['id' => $id, 'data' => $data]);
    }

    public function destroy($id) {
        $stmt = $this->pdo->prepare("DELETE FROM sessions WHERE id = :id");
        return $stmt->execute(['id' => $id]);
    }

    public function gc($maxLifetime) {
        $stmt = $this->pdo->prepare("DELETE FROM sessions WHERE last_access < (NOW() - INTERVAL :lifetime SECOND)");
        return $stmt->execute(['lifetime' => $maxLifetime]);
    }
}
?>
