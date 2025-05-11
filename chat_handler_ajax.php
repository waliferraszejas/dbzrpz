<?php

// /chat_handler_ajax.php (Com include explícito e TIMING para debug de delay)



// Configurações iniciais e de erro

ini_set('display_errors', 1); // Mantenha para depuração

error_reporting(E_ALL);

// IMPORTANTE: Garantir que a resposta seja JSON mesmo se houver erro antes do echo final

header('Content-Type: application/json');



session_start(); // Inicia a sessão



// Resposta padrão de erro (caso algo falhe muito cedo)

$response = ['success' => false, 'error' => 'Erro inicial desconhecido no handler.'];



try { // Envolve tudo em try para capturar erros de include/conexão iniciais



// --- Validação de Segurança Básica ---

if (!isset($_SESSION['user_id'])) {

$response = ['success' => false, 'error' => 'Usuário não autenticado.'];

echo json_encode($response); // Envia JSON antes de sair

exit;

}

$user_id = $_SESSION['user_id'];



// --- Includes ---

// Ajuste os caminhos se necessário.

require_once("conexao.php"); // Garante que $conn (PDO) está disponível

require_once("script/sala_functions.php"); // Garante que formatarNomeUsuarioComTag está disponível



// Verifica a ação requisitada

$action = $_GET['action'] ?? '';



// Reseta a resposta padrão agora que includes funcionaram

$response = ['success' => false, 'error' => 'Ação inválida ou dados ausentes.'];



// --- Processamento da Ação 'send' ---

if ($action === 'send' && $_SERVER['REQUEST_METHOD'] === 'POST') {



$time_start_total = microtime(true); // Tempo inicial total para action=send

error_log("CHAT_SEND [{$user_id}]: Iniciando action=send");



try {

// Pega a mensagem do POST

$message_text = trim($_POST['message'] ?? '');



// Valida a mensagem

if (empty($message_text)) {

$response = ['success' => false, 'error' => 'A mensagem não pode estar vazia.'];

// Não precisa logar tempo aqui, falha rápida

} elseif (mb_strlen($message_text) > 500) {

$response = ['success' => false, 'error' => 'A mensagem é muito longa (máx 500 caracteres).'];

// Não precisa logar tempo aqui, falha rápida

} else {

// --- Medir formatarNomeUsuarioComTag ---

if (!function_exists('formatarNomeUsuarioComTag')) {

error_log("FATAL? [{$user_id}]: Função formatarNomeUsuarioComTag não encontrada em chat_handler_ajax.php.");

throw new Exception("Erro interno: Função de formatação de nome indisponível.");

}

$time_before_format = microtime(true);

// Ajuste se a função esperar SÓ o ID: $username_formatado = formatarNomeUsuarioComTag($conn, $user_id);

$user_data_for_func = ['id' => $user_id];

$username_formatado = formatarNomeUsuarioComTag($conn, $user_data_for_func);

$time_after_format = microtime(true);

$duration_format = $time_after_format - $time_before_format;

error_log("CHAT_SEND_TIME [{$user_id}]: formatarNomeUsuarioComTag demorou: " . number_format($duration_format, 4) . "s");



// Verifica retorno da função

if (strpos($username_formatado, 'Erro') !== false || $username_formatado === 'Usuário Desconhecido' || $username_formatado === 'Usuário Inválido') {

error_log("Falha [{$user_id}]: formatarNomeUsuarioComTag retornou erro: " . $username_formatado);

throw new Exception("Não foi possível obter o nome de usuário formatado. Retorno: " . $username_formatado);

}



// --- Medir INSERT ---

$sql_insert = "INSERT INTO chat_messages (user_id, username, message_text, timestamp) VALUES (:user_id, :username, :message_text, NOW())";

$stmt_insert = $conn->prepare($sql_insert);

$params_insert = [

':user_id' => $user_id,

':username' => $username_formatado, // Salva nome formatado

':message_text' => $message_text // Salva texto original

];



$time_before_execute = microtime(true);

$execute_success = $stmt_insert->execute($params_insert); // execute retorna true/false

$time_after_execute = microtime(true);

$duration_execute = $time_after_execute - $time_before_execute;

error_log("CHAT_SEND_TIME [{$user_id}]: DB INSERT demorou: " . number_format($duration_execute, 4) . "s");



if ($execute_success) {

$response = ['success' => true];

} else {

// Pega informações de erro do PDO Statement, se houver

$errorInfo = $stmt_insert->errorInfo();

error_log("Erro PDO Execute (INSERT Chat) [{$user_id}]: " . print_r($errorInfo, true));

$response = ['success' => false, 'error' => 'Erro ao salvar a mensagem no banco de dados [execute]. SQLSTATE: ' . ($errorInfo[0] ?? 'N/A')];

}

}



} catch (PDOException $e) { // Erros específicos do banco de dados

error_log("Erro PDO Exception no chat_handler_ajax.php (send) [{$user_id}]: " . $e->getMessage());

$response = ['success' => false, 'error' => 'Erro interno (DB) ao processar a mensagem.'];

// Loga tempo total mesmo em caso de erro

$time_end_total = microtime(true);

$duration_total = $time_end_total - $time_start_total;

error_log("CHAT_SEND_TIME [{$user_id}]: Tempo TOTAL action=send (ERRO PDO): " . number_format($duration_total, 4) . "s");



} catch (Exception $ex) { // Outros erros (como falha em formatarNomeUsuarioComTag ou função não encontrada)

error_log("Erro Geral Exception no chat_handler_ajax.php (send) [{$user_id}]: " . $ex->getMessage());

$response = ['success' => false, 'error' => 'Erro interno (Geral) ao processar a mensagem.'];

// Loga tempo total mesmo em caso de erro

if (isset($time_start_total)) { // Garante que $time_start_total foi definido

$time_end_total = microtime(true);

$duration_total = $time_end_total - $time_start_total;

error_log("CHAT_SEND_TIME [{$user_id}]: Tempo TOTAL action=send (ERRO Exception): " . number_format($duration_total, 4) . "s");

}

}



// Log do tempo total se chegou aqui sem exceção e sem ser falha de validação rápida

if (isset($time_start_total) && !isset($ex) && !isset($e) && ($response['success'] || (!empty($message_text) && mb_strlen($message_text) <= 500))) {

$time_end_total = microtime(true);

$duration_total = $time_end_total - $time_start_total;

error_log("CHAT_SEND_TIME [{$user_id}]: Tempo TOTAL action=send (Fim): " . number_format($duration_total, 4) . "s");

}





// --- Processamento da Ação 'fetch' --- (Sem alterações de timing aqui)

} elseif ($action === 'fetch') {



try {

$last_id = filter_input(INPUT_GET, 'last_id', FILTER_VALIDATE_INT) ?: 0;



$sql_fetch = "SELECT id, user_id, username, message_text, timestamp

FROM chat_messages

WHERE id > :last_id

ORDER BY id ASC

LIMIT 50";

https://srv1886-files.hstgr.io/8e68139380edf82b/files/domains/dbzrpgonline.click/public_html/chat_handler_ajax.php

$stmt_fetch = $conn->prepare($sql_fetch);

$stmt_fetch->bindValue(':last_id', $last_id, PDO::PARAM_INT);

$stmt_fetch->execute();



$new_messages = $stmt_fetch->fetchAll(PDO::FETCH_ASSOC);



foreach ($new_messages as $key => $msg) {

$new_messages[$key]['message_text'] = htmlspecialchars($msg['message_text']);

try {

$dateTime = new DateTime($msg['timestamp']);

$new_messages[$key]['timestamp_formatted'] = $dateTime->format('H:i');

} catch (Exception $dateEx) {

$new_messages[$key]['timestamp_formatted'] = '??:??';

}

}



$response = ['success' => true, 'messages' => $new_messages];



} catch (PDOException $e) {

error_log("Erro PDO no chat_handler_ajax.php (fetch) [{$user_id}]: " . $e->getMessage());

$response = ['success' => false, 'error' => 'Erro interno no servidor ao buscar mensagens.'];

} catch (Exception $ex) {

error_log("Erro Geral no chat_handler_ajax.php (fetch) [{$user_id}]: " . $ex->getMessage());

$response = ['success' => false, 'error' => 'Erro interno (Geral) ao buscar mensagens.'];

}

}

// --- Fim do processamento das ações ---



} catch(Exception $initError) { // Captura erros nos includes ou conexão inicial

error_log("Erro Inicial CRÍTICO em chat_handler_ajax.php: " . $initError->getMessage());

// $response já tem um erro genérico definido no início, ou será sobrescrito se a sessão falhar

if (!isset($_SESSION['user_id'])) { // Checa novamente se a sessão era o problema

$response = ['success' => false, 'error' => 'Usuário não autenticado (verificação inicial falhou).'];

} else {

$response = ['success' => false, 'error' => 'Erro crítico na inicialização do handler de chat. Verifique os logs.'];

}

// Garante que o header JSON seja enviado mesmo com erro inicial

if (!headers_sent()) {

header('Content-Type: application/json');

}

}



// --- Envia a Resposta Final ---

echo json_encode($response);

exit;



?>