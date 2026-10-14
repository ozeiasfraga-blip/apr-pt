<?php

namespace App\Models;

use App\Database;

class User
{
    private const PUBLIC_COLUMNS = 'id, name, email, role, ixc_funcionario_id, ativo, created_at, updated_at';

    public static function getAll(): array
    {
        $stmt = Database::getInstance()->query(
            'SELECT ' . self::PUBLIC_COLUMNS . ' FROM users ORDER BY name ASC'
        );
        return $stmt->fetchAll();
    }

    public static function getById(int $id): ?array
    {
        $stmt = Database::getInstance()->prepare(
            'SELECT ' . self::PUBLIC_COLUMNS . ' FROM users WHERE id = ?'
        );
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public static function getByEmail(string $email): ?array
    {
        $stmt = Database::getInstance()->prepare('SELECT * FROM users WHERE email = ?');
        $stmt->execute([$email]);
        return $stmt->fetch() ?: null;
    }

    public static function getByIxcId(int $ixcFuncionarioId): ?array
    {
        $stmt = Database::getInstance()->prepare(
            'SELECT ' . self::PUBLIC_COLUMNS . ' FROM users WHERE ixc_funcionario_id = ?'
        );
        $stmt->execute([$ixcFuncionarioId]);
        return $stmt->fetch() ?: null;
    }

    public static function create(array $data): int
    {
        $db = Database::getInstance();
        $stmt = $db->prepare(
            'INSERT INTO users (name, email, password_hash, role, ixc_funcionario_id, ativo)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $data['name'],
            $data['email'],
            $data['password_hash'],
            $data['role'] ?? 'tecnico',
            $data['ixc_funcionario_id'] ?? null,
            isset($data['ativo']) ? (int)$data['ativo'] : 1,
        ]);
        return (int)$db->lastInsertId();
    }

    public static function update(int $id, array $data): void
    {
        $fields = [];
        $values = [];

        foreach (['name', 'email', 'role', 'ixc_funcionario_id', 'ativo'] as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "{$field} = ?";
                $values[] = $data[$field];
            }
        }

        if (!empty($fields)) {
            $values[] = $id;
            $stmt = Database::getInstance()->prepare(
                'UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = ?'
            );
            $stmt->execute($values);
        }
    }

    public static function updatePassword(int $id, string $passwordHash): void
    {
        $stmt = Database::getInstance()->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
        $stmt->execute([$passwordHash, $id]);
    }

    public static function delete(int $id): void
    {
        $stmt = Database::getInstance()->prepare('DELETE FROM users WHERE id = ?');
        $stmt->execute([$id]);
    }

    public static function createToken(int $userId, string $token, int $expiresIn): void
    {
        $db = Database::getInstance();
        $stmt = $db->prepare(
            'INSERT INTO auth_tokens (user_id, token, expires_at)
             VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND))'
        );
        $stmt->execute([$userId, $token, $expiresIn]);
    }

    public static function findToken(string $token): ?array
    {
        $stmt = Database::getInstance()->prepare(
            'SELECT * FROM auth_tokens WHERE token = ? AND expires_at > NOW()'
        );
        $stmt->execute([$token]);
        return $stmt->fetch() ?: null;
    }

    public static function deleteToken(string $token): void
    {
        $stmt = Database::getInstance()->prepare('DELETE FROM auth_tokens WHERE token = ?');
        $stmt->execute([$token]);
    }

    public static function deleteUserTokens(int $userId): void
    {
        $stmt = Database::getInstance()->prepare('DELETE FROM auth_tokens WHERE user_id = ?');
        $stmt->execute([$userId]);
    }

    /**
     * Conta os vínculos que impedem a exclusão física (histórico).
     * Retorna ['documentos' => n, 'assinaturas' => n].
     */
    public static function countVinculos(int $id): array
    {
        $db = Database::getInstance();

        $stmt = $db->prepare(
            'SELECT COUNT(*) FROM documentos_sst WHERE tecnico_id = ? OR supervisor_id = ?'
        );
        $stmt->execute([$id, $id]);
        $documentos = (int)$stmt->fetchColumn();

        $stmt = $db->prepare('SELECT COUNT(*) FROM assinaturas WHERE user_id = ?');
        $stmt->execute([$id]);
        $assinaturas = (int)$stmt->fetchColumn();

        return ['documentos' => $documentos, 'assinaturas' => $assinaturas];
    }
}
