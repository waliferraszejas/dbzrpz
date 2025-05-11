<?php

// --- Configurações ---
define('POLL_DURATION', 28); // Segundos que o servidor vai esperar por mensagens
define('SLEEP_INTERVAL', 2); // Segundos entre verificações no DB

// Ignora se o usuário abortar, aumenta o tempo limite
ignore_user_abort(true);
set_time_limit(POLL_DURATION + 5); // Define tempo limite um pouco maior que a duração do poll

// --- Conexão e Sessão (Opcional, dependendo se precisa de user_id) ---
session_start(); // Inicia se precisar verificar algo do usuário logado
require_once('conexao.php'); // Assume que $conn está aqui

// --- Input e Sanitização ---
// Pega o ID da última mensagem que o cliente já tem
$last_id = isset($_GET['last_id']) ? (int)$_GET['last_id'] : 0;
// Poderia adicionar validação do user_id da sessão aqui se necessário para segurança

// --- Loop de Espera (Long Polling) ---
$start_time = time();

try {
    while (time() < $start_time + POLL_DURATION) {
        // Verifica se o cliente ainda está conectado
        if (connection_aborted()) {
            error_log("Long poll connection aborted by client.");
            exit();
        }

        // Prepara a query para buscar novas mensagens
        // Busca mensagens DEPOIS do last_id recebido
        $sql = "SELECT id, user_id, username, message_text, timestamp
                FROM chat_messages
                WHERE id > :last_id
                ORDER BY id ASC
                LIMIT 50"; // Limita para não sobrecarregar se houver muitas msgs de uma vez

        $stmt = $conn->prepare($sql);
        $stmt->bindValue(':last_id', $last_id, PDO::PARAM_INT);
        $stmt->execute();

        $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Se encontrou novas mensagens...
        if (!empty($messages)) {
            // Envia a resposta JSON e termina o script
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'messages' => $messages]);
            exit();
        }

        // Se não encontrou, espera um pouco antes de verificar novamente
        sleep(SLEEP_INTERVAL);
    }

    // Se o loop terminou (timeout) sem novas mensagens...
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'messages' => []]); // Resposta vazia
    exit();

} catch (PDOException $e) {
    error_log("Erro PDO no chat_longpoll.php: " . $e->getMessage());
    header('Content-Type: application/json', true, 500); // Internal Server Error
    echo json_encode(['success' => false, 'error' => 'Erro no banco de dados do chat.']);
    exit();
} catch (Exception $ex) {
    error_log("Erro Geral no chat_longpoll.php: " . $ex->getMessage());
    header('Content-Type: application/json', true, 500); // Internal Server Error
    echo json_encode(['success' => false, 'error' => 'Erro inesperado no servidor do chat.']);
    exit();
}

?>