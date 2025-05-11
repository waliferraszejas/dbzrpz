<?php
// --- Arquivo: conexao.php (MODIFICADO PARA HORÁRIO DE BRASÍLIA) ---

// --- ADICIONADO: Define o fuso horário padrão para o PHP ---
date_default_timezone_set('America/Sao_Paulo');
// ----------------------------------------------------------

$servidor = "localhost";
$usuario = "u305246487_fehlimaxd";
$senha_banco = "Pssword9254";
$banco = "u305246487_dbzwali";
$charset = "utf8mb4"; // Charset recomendado

// Opções do PDO (mantidas)
$opcoes_pdo = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION, // Lança exceções em erros
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,       // Retorna arrays associativos por padrão
    PDO::ATTR_EMULATE_PREPARES   => false,                  // Usa prepared statements nativos do DB
];

// Data Source Name (DSN)
$dsn = "mysql:host=$servidor;dbname=$banco;charset=$charset";

try {
    // Cria a instância PDO
    $conn = new PDO($dsn, $usuario, $senha_banco, $opcoes_pdo);

    // --- ADICIONADO: Define o fuso horário para a conexão MySQL ---
    try {
        $conn->exec("SET time_zone = '-03:00'");
         // Alternativa: $conn->exec("SET time_zone = '-03:00'"); (Verificar offset atual se usar esta)
    } catch (PDOException $e) {
        error_log("Erro ao definir time_zone da conexão PDO: " . $e->getMessage());
        // Considerar o que fazer aqui - talvez não seja crítico falhar, mas bom logar.
    }
    // -------------------------------------------------------------

} catch (PDOException $e) {
    // Em caso de erro grave na conexão, registra o erro e termina
    error_log("Erro de conexão PDO: " . $e->getMessage()); // Loga o erro real
    // Você pode querer uma página de erro mais amigável aqui em produção
    die("Erro de conexão com o banco de dados. Por favor, tente novamente mais tarde.");
}

?>