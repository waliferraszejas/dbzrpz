<?php
session_start();

// Exibe os erros (Mantenha para desenvolvimento, considere desativar display_errors em produção)
ini_set('display_errors', 1); // Mantenha 0 ou comente em produção
error_reporting(E_ALL);      // Mantenha E_ALL & ~E_NOTICE ou 0 em produção

// Inclui a conexão PDO
require_once('conexao.php'); // Garante que $conn (PDO) está definido

// Verifica se o usuário já está logado
if (isset($_SESSION['user_id'])) {
    header("Location: home.php"); // Redireciona para a home se já logado
    exit();
}

// --- INÍCIO: Tratamento de Erros de Login (via Session após redirect) ---
$erro = null; // Inicializa a variável de erro
if (isset($_SESSION['login_error'])) {
    $erro = $_SESSION['login_error']; // Pega o erro da sessão (se houver)
    unset($_SESSION['login_error']); // Limpa o erro da sessão para não mostrar de novo
}
// --- FIM: Tratamento de Erros de Login ---

// Verifica se o formulário foi enviado
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (!empty($_POST['usuario']) && !empty($_POST['senha'])) {

        // --- MODIFICAÇÃO: Case-Insensitive ---
        // Limpa espaços e converte para minúsculas para comparação
        $usuario_post_lower = strtolower(trim($_POST['usuario']));
        $senha_post = $_POST['senha']; // Senha não deve ter trim ou lower

        try {
            // --- MODIFICAÇÃO: Case-Insensitive (SQL) ---
            // Compara o LOWER(login) do banco com o :login_lower fornecido
            $sql = "SELECT id, nome, senha, login FROM usuarios WHERE LOWER(login) = :login_lower LIMIT 1";
            $stmt = $conn->prepare($sql);

            if ($stmt) {
                // Executa a query passando o login em minúsculas
                if ($stmt->execute([':login_lower' => $usuario_post_lower])) {
                    $usuario_bd = $stmt->fetch(PDO::FETCH_ASSOC);

                    if ($usuario_bd) {
                        // Usuário encontrado, verifica a senha hashada
                        if (password_verify($senha_post, $usuario_bd['senha'])) {
                            // Senha correta - Login bem-sucedido!
                            session_regenerate_id(true); // Regenera ID da sessão por segurança
                            $_SESSION['user_id'] = $usuario_bd['id'];
                            // Guarda o nome e o login originais (com case) na sessão, pode ser útil
                            $_SESSION['nome'] = $usuario_bd['nome'];
                            $_SESSION['login'] = $usuario_bd['login']; // Guarda o login como está no BD

                            // Redireciona para a home
                            header("Location: home.php");
                            exit(); // Garante que o script para após o redirect
                        } else {
                            // Senha incorreta
                            // --- MODIFICAÇÃO: Redirecionamento em Erro ---
                            $_SESSION['login_error'] = "Login ou Senha incorreta!"; // Mensagem genérica por segurança
                            header("Location: index.php");
                            exit();
                        }
                    } else {
                        // Nenhum usuário encontrado com esse login (case-insensitive)
                        // --- MODIFICAÇÃO: Redirecionamento em Erro ---
                        $_SESSION['login_error'] = "Login ou Senha incorreta!"; // Mensagem genérica por segurança
                        header("Location: index.php");
                        exit();
                    }
                } else {
                    // Erro na execução do statement
                    error_log("Erro ao executar statement de login (PDO): " . implode(":", $stmt->errorInfo())); // Loga o erro PDO
                    // --- MODIFICAÇÃO: Redirecionamento em Erro ---
                    $_SESSION['login_error'] = "Erro ao processar login. Tente novamente.";
                    header("Location: index.php");
                    exit();
                }
            } else {
                // Erro na preparação do statement (geralmente capturado pelo catch com ERRMODE_EXCEPTION)
                error_log("Erro ao preparar statement de login (PDO)");
                // --- MODIFICAÇÃO: Redirecionamento em Erro ---
                $_SESSION['login_error'] = "Erro interno do servidor [PREPARE]. Tente novamente mais tarde.";
                header("Location: index.php");
                exit();
            }
        } catch (PDOException $e) {
            error_log("Erro PDO no login: " . $e->getMessage()); // Loga o erro PDO real
            // --- MODIFICAÇÃO: Redirecionamento em Erro ---
            $_SESSION['login_error'] = "Erro interno do servidor [DB]. Tente novamente mais tarde.";
            header("Location: index.php");
            exit();
        }
    } else {
        // Campos não preenchidos
        // --- MODIFICAÇÃO: Redirecionamento em Erro ---
        $_SESSION['login_error'] = "Por favor, preencha o usuário e a senha.";
        header("Location: index.php");
        exit();
    }
}

// --- Lógica para carregar notas de atualização (mantida) ---
$novidades_html = '';
$notas_file = 'notas_atualizacao.html';
if (file_exists($notas_file)) {
    $novidades_html = file_get_contents($notas_file);
} else {
    $novidades_html = "<p style='color: var(--text-secondary); text-align: center;'>Não foi possível carregar as novidades.</p>";
}
// --- Fim notas ---

?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - RPG Online</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&display=swap" rel="stylesheet">
    <style>
        /* ---> Seu CSS Completo Aqui (sem alterações necessárias) <--- */
         :root { /* ... variáveis CSS ... */ --bg-image-url: url('https://images.alphacoders.com/772/thumb-1920-772375.jpg'); --panel-bg: rgba(25, 28, 36, 0.88); --panel-border: rgba(255, 255, 255, 0.1); --text-primary: #e0e0e0; --text-secondary: #a0a0a0; --accent-color-1: #00e676; --accent-color-2: #ffab00; --accent-color-3: #2979ff; --danger-color: #ff5252; --disabled-color: #555e6d; --border-radius: 12px; --shadow: 0 6px 20px rgba(0, 0, 0, 0.4); }
         * { margin: 0; padding: 0; box-sizing: border-box; }
         body { background-image: var(--bg-image-url); background-size: cover; background-position: center; background-attachment: fixed; color: var(--text-primary); font-family: 'Roboto', sans-serif; margin: 0; padding: 25px; min-height: 100vh; background-color: #111; display: flex; align-items: center; justify-content: center; }
         .container { width: 100%; max-width: 950px; display: flex; justify-content: center; gap: 25px; align-items: stretch; }
         .coluna { background-color: var(--panel-bg); border-radius: var(--border-radius); padding: 30px; box-shadow: var(--shadow); border: 1px solid var(--panel-border); backdrop-filter: blur(5px); display: flex; flex-direction: column; }
         .coluna-central { width: 45%; min-width: 300px; color: var(--text-primary); }
         .coluna-central h2 { color: #fff; font-weight: 700; font-size: 1.8rem; margin-bottom: 30px; text-align: center; flex-shrink: 0; }
         .form-login { display: flex; flex-direction: column; gap: 20px; width: 100%; margin-top: auto; margin-bottom: auto; }
         .form-login .form-group { display: flex; flex-direction: column; gap: 8px; }
         .form-login label { font-weight: 500; color: var(--text-secondary); font-size: 0.9rem; }
         .form-login input[type="text"], .form-login input[type="password"] { width: 100%; padding: 14px 16px; border-radius: 8px; border: 1px solid var(--panel-border); background-color: rgba(0,0,0,0.3); color: var(--text-primary); font-size: 1rem; transition: border-color 0.2s ease, box-shadow 0.2s ease; }
         .form-login input:focus { outline: none; border-color: var(--accent-color-3); box-shadow: 0 0 0 3px rgba(41, 121, 255, 0.3); }
         .form-login button[type="submit"] { padding: 14px 20px; background: linear-gradient(45deg, var(--accent-color-3), #448aff); color: #fff; border: none; border-radius: 8px; font-weight: 700; font-size: 1.05rem; cursor: pointer; transition: all 0.2s ease; margin-top: 10px; }
         .form-login button[type="submit"]:hover { filter: brightness(1.15); box-shadow: 0 4px 12px rgba(41, 121, 255, 0.4); }
         /* --- MODIFICAÇÃO: Exibição do erro --- */
         .login-error-message { color: var(--danger-color); font-weight: 500; text-align: center; font-size: 0.95rem; background-color: rgba(255, 82, 82, 0.1); padding: 12px; border-radius: 6px; border: 1px solid var(--danger-color); margin-bottom: 15px; flex-shrink: 0; }
         /* Estilo para mensagem de sucesso (feedback) */
         .login-feedback-message { color: var(--accent-color-1); background-color: rgba(0, 230, 118, 0.1); border-color: var(--accent-color-1); font-weight: 500; text-align: center; font-size: 0.95rem; padding: 12px; border-radius: 6px; border: 1px solid var(--accent-color-1); margin-bottom: 15px; flex-shrink: 0; }
         .forgot-pw-link { color: var(--text-secondary); font-size: 0.85rem; text-align: center; margin-top: 15px; text-decoration: none; flex-shrink: 0; }
         .forgot-pw-link:hover { color: var(--accent-color-3); text-decoration: underline; }
         .coluna-lateral-esquerda { width: 25%; min-width: 200px; padding: 15px; gap: 15px; align-items: center; justify-content: center; }
         .coluna-novidades { width: 30%; min-width: 250px; padding: 20px; gap: 15px; }
         .coluna-novidades h3 { color: var(--accent-color-2); font-weight: 700; font-size: 1.3rem; margin-bottom: 15px; text-align: center; padding-bottom: 10px; border-bottom: 1px solid var(--panel-border); flex-shrink: 0; }
         .news-content-wrapper { max-height: 380px; overflow-y: auto; padding-right: 10px; margin-right: -10px; flex-grow: 1; }
         .news-content-wrapper h2 { font-size: 1.1rem; color: var(--accent-color-1); margin-top: 15px; margin-bottom: 8px; padding-bottom: 5px; border-bottom: 1px dashed var(--panel-border); font-weight: 500; }
         .news-content-wrapper h2:first-of-type { margin-top: 0; border-top: none; }
         .news-content-wrapper p { font-size: 0.9rem; color: var(--text-secondary); margin-bottom: 10px; line-height: 1.6; }
         .news-content-wrapper ul { list-style: none; padding-left: 0; margin-bottom: 15px; }
         .news-content-wrapper li { font-size: 0.9rem; margin-bottom: 8px; padding-left: 18px; position: relative; }
         .news-content-wrapper li::before { content: '»'; color: var(--accent-color-2); position: absolute; left: 0; top: 0; }
         .news-content-wrapper ul ul { margin-top: 5px; margin-bottom: 8px; padding-left: 15px;}
         .news-content-wrapper ul ul li::before { content: '-'; }
         .news-content-wrapper b, .news-content-wrapper strong { color: var(--text-primary); font-weight: 500; }
         .news-content-wrapper hr { border: none; border-top: 1px solid var(--panel-border); margin: 18px 0; }
         .news-content-wrapper::-webkit-scrollbar { width: 6px; }
         .news-content-wrapper::-webkit-scrollbar-track { background: rgba(0,0,0,0.1); border-radius: 3px;}
         .news-content-wrapper::-webkit-scrollbar-thumb { background-color: rgba(255, 255, 255, 0.2); border-radius: 3px; }
         .news-content-wrapper::-webkit-scrollbar-thumb:hover { background-color: rgba(255, 255, 255, 0.4); }
         .nav-button { display: block; width: 100%; padding: 14px 20px; background-color: rgba(45, 52, 66, 0.8); color: var(--text-primary); text-decoration: none; text-align: center; border-radius: 8px; border: 1px solid rgba(255, 255, 255, 0.1); font-size: 0.95rem; font-weight: 500; transition: all 0.2s ease; }
         .nav-button:hover { background-color: rgba(55, 62, 76, 0.9); border-color: rgba(255, 255, 255, 0.2); color: #fff; transform: translateY(-2px); }
         .nav-button.register { background-color: rgba(0, 230, 118, 0.2); border-color: var(--accent-color-1); }
         .nav-button.register:hover { background-color: rgba(0, 230, 118, 0.4); }
         @media (max-width: 900px) {
             body { align-items: flex-start; padding-top: 30px; }
             .container { flex-direction: column; align-items: center; width: 95%; max-width: 500px; }
             .coluna { width: 100% !important; margin-bottom: 25px; height: auto; }
             .coluna-lateral-esquerda { order: 2; }
             .coluna-central { order: 1; }
             .coluna-novidades { order: 3; }
             .news-content-wrapper { max-height: 300px; }
         }
          @media (max-width: 480px) {
               body { padding: 15px; padding-top: 20px; }
               .coluna { padding: 20px;}
               .coluna-central h2 { font-size: 1.5rem; margin-bottom: 20px; }
               .form-login { gap: 15px;}
               .form-login input[type="text"], .form-login input[type="password"] { padding: 13px 15px; font-size: 1rem;}
               .form-login button[type="submit"] { font-size: 1rem; padding: 13px 15px;}
               .nav-button { font-size: 0.9rem; padding: 13px 15px;}
               .coluna-novidades h3 { font-size: 1.2rem; }
               .news-content-wrapper h2 { font-size: 1rem; }
               .news-content-wrapper p, .news-content-wrapper li { font-size: 0.85rem; }
               .news-content-wrapper { max-height: 250px; }
           }
    </style>
</head>
<body>

    <div class="container">
        <div class="coluna coluna-lateral-esquerda">
              <a class="nav-button register" href="cadastro.php">Não tem conta? Cadastre-se!</a>
              <a class="nav-button" href="index.php" style="margin-top:10px;">Página Inicial</a>
              </div>

        <div class="coluna coluna-central">
            <h2>Login</h2>

            <?php
            // --- MODIFICAÇÃO: Exibição do erro (obtido da session no topo do script) ---
            if ($erro):
            ?>
                <p class="login-error-message"><?= htmlspecialchars($erro) ?></p>
            <?php
            endif;

            // Exibe feedback de outras ações (ex: cadastro bem-sucedido), se existir
            // Use uma classe CSS diferente para feedback positivo
            if (isset($_SESSION['feedback_login'])) {
                echo '<p class="login-feedback-message">' . htmlspecialchars($_SESSION['feedback_login']) . '</p>';
                unset($_SESSION['feedback_login']); // Limpa a mensagem após exibir
            }
            ?>

            <form class="form-login" method="POST" action="index.php">
                <div class="form-group">
                    <label for="usuario">Usuário (Login):</label>
                    <?php /* O valor antigo não será mantido após redirect, mas não causa erro */ ?>
                    <input type="text" id="usuario" name="usuario" placeholder="Seu nome de login" required value="<?= isset($_POST['usuario']) ? htmlspecialchars($_POST['usuario']) : '' ?>">
                </div>
                <div class="form-group">
                    <label for="senha">Senha:</label>
                    <input type="password" id="senha" name="senha" placeholder="Sua senha" required>
                </div>
                <button type="submit">Entrar</button>
                <a class="forgot-pw-link" href="recuperar_senha.php">Esqueceu sua senha?</a>
             </form>
        </div>

        <div class="coluna coluna-novidades">
            <h3>O que há de novo?</h3>
            <div class="news-content-wrapper">
                <?php echo $novidades_html; // Exibe o conteúdo das notas ?>
            </div>
        </div>
    </div>

</body>
</html>
<?php
// Não é necessário fechar a conexão PDO explicitamente na maioria dos casos
?>
