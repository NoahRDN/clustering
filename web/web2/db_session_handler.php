<?php
class DBSessionHandler implements SessionHandlerInterface {
    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    public function open(string $path, string $name): bool { return true; }
    public function close(): bool { return true; }

    public function read(string $id): string {
        $stmt = $this->pdo->prepare("SELECT data FROM sessions WHERE id = :id");
        $stmt->execute(['id' => $id]);
        return (string) $stmt->fetchColumn() ?: '';
    }

    public function write(string $id, string $data): bool {
        $stmt = $this->pdo->prepare("
            INSERT INTO sessions (id, data) VALUES (:id, :data)
            ON DUPLICATE KEY UPDATE data = :data, last_access = CURRENT_TIMESTAMP
        ");
        return $stmt->execute(['id' => $id, 'data' => $data]);
    }

    public function destroy(string $id): bool {
        $stmt = $this->pdo->prepare("DELETE FROM sessions WHERE id = :id");
        return $stmt->execute(['id' => $id]);
    }

    public function gc(int $max_lifetime): int|false {
        $stmt = $this->pdo->prepare("DELETE FROM sessions WHERE last_access < (NOW() - INTERVAL :lt SECOND)");
        $stmt->execute(['lt' => $max_lifetime]);
        return $stmt->rowCount();
    }
}
