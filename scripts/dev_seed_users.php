<?php

// scripts/dev_seed_users.php
// Dev-скрипт для создания/обновления базовых пользователей.

require_once __DIR__ . '/../app/db.php';

$pdo = db();

function upsert_user(PDO $pdo, string $email, string $password, string $globalRole): void
{
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = :email LIMIT 1");
    $stmt->execute([':email' => $email]);
    $id = $stmt->fetchColumn();

    $hash = password_hash($password, PASSWORD_DEFAULT);

    if ($id) {
        $upd = $pdo->prepare("
            UPDATE users
            SET password_hash = :hash,
                global_role   = :role
            WHERE id = :id
        ");
        $upd->execute([
            ':hash' => $hash,
            ':role' => $globalRole,
            ':id'   => (int)$id,
        ]);
        echo "Updated user {$email} (id={$id}) with role {$globalRole}\n";
    } else {
        $ins = $pdo->prepare("
            INSERT INTO users (email, password_hash, global_role, created_at, updated_at)
            VALUES (:email, :hash, :role, NOW(), NOW())
        ");
        $ins->execute([
            ':email' => $email,
            ':hash'  => $hash,
            ':role'  => $globalRole,
        ]);
        $newId = (int)$pdo->lastInsertId();
        echo "Created user {$email} (id={$newId}) with role {$globalRole}\n";
    }
}

upsert_user($pdo, 'admin@lvh.me', 'Admin123!', 'project_owner');
upsert_user($pdo, 'owner@lvh.me', 'Owner123!', 'owner');

echo "Done.\n";

