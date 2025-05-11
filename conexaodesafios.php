<?php
// --- Arquivo: conexao.php (CORRIGIDO) ---

// --- ADICIONADO: Define o fuso horário padrão para o PHP ---
date_default_timezone_set('America/Sao_Paulo');
// ----------------------------------------------------------

// --- Configurações do Banco de Dados ---
$servidor = "localhost"; // Ou o IP/hostname do seu servidor de banco de dados
$usuario_db = "u305246487_fehlimaxd"; // Seu usuário do banco de dados
$senha_banco = "Pssword9254"; // Sua senha do banco de dados
$banco = "u305246487_dbzwali"; // Nome do seu banco de dados
$charset = "utf8mb4"; // Charset recomendado

// Data Source Name (DSN) - String de conexão para o PDO
// CORRIGIDO: Usando as variáveis $servidor, $banco, $charset
$dsn = "mysql:host=$servidor;dbname=$banco;charset=$charset";

// Opções para a conexão PDO
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION, // Lança Exceptions em caso de erro (RECOMENDADO)
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,     // Retorna resultados como array associativo por padrão
    PDO::ATTR_EMULATE_PREPARES   => false,              // Desativa emulações para prepared statements (MAIS SEGURO)
    // PDO::MYSQL_ATTR_SSL_CA     => '/path/to/cert.pem',  // Opcional: Se estiver usando SSL para a conexão com o DB
];

// --- Tentativa de Conexão ---
try {
    // Cria a instância PDO
    // CORRIGIDO: Usando as variáveis $usuario_db, $senha_banco
    $conn = new PDO($dsn, $usuario_db, $senha_banco, $options);

    // Nota: Se você quiser definir ATTR_ERRMODE e ATTR_EMULATE_PREPARES
    // nas páginas que incluem conexao.php, remova-os das $options e defina nas páginas.
    // Manter nas $options aqui é o método mais comum e geralmente suficiente.

} catch (\PDOException $e) {
    // Captura exceções PDO durante a conexão
    // Em ambiente de PRODUÇÃO, NUNCA mostre $e->getMessage() diretamente!
    // Apenas logue o erro. A página que incluiu este arquivo (desafios.php, handler.php)
    // deve ter um try/catch para tratar o erro para o usuário.
    error_log("Erro de Conexão PDO em " . basename(__FILE__) . ": " . $e->getMessage()); // Loga o erro completo no servidor
    // Re-lança a exceção para ser capturada pelo código que incluiu conexao.php
    // Não chame die() ou exit() aqui, a menos que você QUEIRA que a página morra neste ponto.
    // Como nossas páginas incluindo usam try/catch, relançar é apropriado.
    throw $e;
}

// --- Função Auxiliar para Formatar Nome de Usuário com Tag ---
// Esta função é usada em desafios.php e challenge_handler.php.
// Ela recebe a conexão PDO e o ID do usuário, busca o nome
// e retorna o nome formatado/sanitizado.
// Implemente a lógica da sua tag aqui se necessário.
if (!function_exists('formatarNomeUsuarioComTag')) {
    function formatarNomeUsuarioComTag(PDO $conn, int $user_id): string {
        try {
            // Note: Usa o $conn passado como parâmetro, não o global
            $sql = "SELECT nome FROM usuarios WHERE id = :user_id LIMIT 1";
            $stmt = $conn->prepare($sql);
            $stmt->execute([':user_id' => $user_id]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user && isset($user['nome'])) {
                // --- Lógica da sua TAG (IMPLEMENTE AQUI) ---
                // Por padrão, apenas sanitiza o nome.
                return htmlspecialchars($user['nome']);

                // Exemplo com tag:
                /*
                $tag = ""; // Lógica para obter a tag do usuário pelo $user_id ou outros dados
                if (!empty($tag)) {
                     return htmlspecialchars($user['nome']) . " [" . htmlspecialchars($tag) . "]";
                } else {
                     return htmlspecialchars($user['nome']);
                }
                */
                // --- Fim da Lógica da sua TAG ---

            } else {
                // Caso o usuário não seja encontrado (inesperado se o ID for válido)
                return "Usuário Desconhecido (ID: " . htmlspecialchars($user_id) . ")"; // Incluir ID para debug?
            }
        } catch (\PDOException $e) {
            // Loga o error, mas não impede a página de carregar com fallback
            error_log("Erro PDO em formatarNomeUsuarioComTag para user ID {$user_id}: " . $e->getMessage());
            return "Erro ao carregar nome"; // Retorna um fallback seguro
        } catch (Exception $e) { // Captura outras exceções
             error_log("Erro GERAL em formatarNomeUsuarioComTag para user ID {$user_id}: " . $e->getMessage());
            return "Erro ao carregar nome"; // Retorna um fallback seguro
        }
    }
}

// A variável $conn está agora disponível para os arquivos que incluírem este,
// SE a conexão foi bem-sucedida. Se não, uma exceção foi lançada e logada.
?>