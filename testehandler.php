<?php
// test_handler.php - Script minimalista para testar inicialização e conexão

// --- CONFIGURAÇÃO DE DEPURAÇÃO ---
// Configure o log de erros para um arquivo diferente neste script de teste
error_reporting(E_ALL); // Reporta todos os erros PHP
ini_set('display_errors', 1); // Tenta exibir erros (REMOVER/DESATIVAR em PRODUÇÃO!)
ini_set('log_errors', 1); // Garante que os erros sejam registrados
ini_set('error_log', __DIR__ . '/php_error_test_handler.log'); // Log específico para este teste
// --- FIM DA CONFIGURAÇÃO DE DEPURAÇÃO ---

session_start(); // Inicia a sessão DEPOIS do ini_set

// Define que a resposta será texto simples para vermos o output mais fácil
header('Content-Type: text/plain; charset=UTF-8');

echo "Teste de inicialização iniciado.\n";

// Verifica login (apenas um check básico)
if (!isset($_SESSION['user_id'])) {
    echo "Status de login: Não autenticado.\n";
    // Não vamos parar aqui, queremos testar a conexão mesmo sem login para debug
} else {
    echo "Status de login: Autenticado (User ID: " . (int)$_SESSION['user_id'] . ").\n";
}

echo "Tentando incluir conexao.php...\n";

// --- TENTATIVA DE CONEXÃO E INCLUSÃO ---
try {
    // Assumimos que conexao.php está na mesma pasta
require_once(__DIR__ . "/conexao.php");

    echo "conexao.php incluído com sucesso.\n";

    // Verifica se a variável $conn foi definida e é uma instância PDO
    if (isset($conn) && $conn instanceof PDO) {
        echo "Variável \$conn encontrada e é uma instância PDO.\n";
        // Tenta executar uma query simples para testar a conexão ativa
        try {
            $stmt = $conn->query("SELECT 1");
            if ($stmt) {
                echo "Teste de query simples ao DB bem-sucedido.\n";
                 // Loga sucesso no log de erros, apenas para ter certeza que o log funciona
                 error_log("DEBUG TEST: Conexão e query simples bem-sucedidas em test_handler.php");
            } else {
                 echo "Variável \$conn encontrada, mas query de teste falhou.\n";
                 error_log("DEBUG TEST: \$conn encontrada, mas query de teste falhou em test_handler.php");
            }
        } catch (\PDOException $e) {
            echo "Variável \$conn encontrada, mas query de teste PDO falhou: " . $e->getMessage() . "\n";
             error_log("DEBUG TEST: Query de teste PDO falhou em test_handler.php: " . $e->getMessage());
        }

    } else {
        echo "Erro: Variável \$conn NÃO encontrada ou NÃO é uma instância PDO após incluir conexao.php.\n";
        error_log("DEBUG TEST: \$conn NÃO encontrada ou NÃO é instância PDO após incluir conexao.php em test_handler.php");
         // Se isso acontecer, significa que conexao.php não definiu $conn corretamente
    }

} catch (\Throwable $e) { // Captura QUALQUER erro (inclusão, conexão, etc.)
    echo "ERRO CRÍTICO durante a inicialização ou conexão: " . $e->getMessage() . "\n";
    echo "Por favor, verifique o log de erros para mais detalhes.\n";
    // Loga o erro completo neste script de teste
    error_log("ERRO CRÍTICO em test_handler.php: " . $e->getMessage() . " | Trace: " . $e->getTraceAsString());

    // Comenta a linha abaixo para evitar um segundo log se o error_log acima já funcionou
    // header('HTTP/1.1 500 Internal Server Error'); // Não enviamos 500 aqui, queremos ver o output
}

echo "Fim do teste de inicialização.\n";

// Não há lógica de desafios aqui, apenas testamos a base.
// Se este script falhar ou não mostrar todo o output, o problema é muito básico.

?>