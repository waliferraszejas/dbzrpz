<?php
ini_set('display_errors', 1); // Mantenha 0 ou comente em produção
error_reporting(E_ALL);      // Mantenha E_ALL & ~E_NOTICE ou 0 em produção
session_start();

require_once('conexao.php'); // Sua conexão PDO ($conn)

// --- Configurações ---
$tempo_expiracao_token_horas = 1; // Token expira em 1 hora
$url_base_site = "https://dbzrpgonline.click"; // MUDE PARA A URL BASE DO SEU SITE (sem barra no final)
$arquivo_reset = "recuperar_senha.php"; // Nome deste arquivo

// --- Inicialização de Variáveis ---
$estado_atual = 'mostrar_form_email'; // Estados: 'mostrar_form_email', 'processar_email', 'mostrar_form_reset', 'processar_reset', 'mostrar_mensagem'
$mensagem_geral = null;
$tipo_mensagem_geral = 'info'; // 'info', 'success', 'error'
$mensagem_erro_validacao = []; // Erros específicos de campos
$email_post = null; // Para manter o valor no campo email após erro
$mostrar_formulario_reset = false;

// --- Determinar o Estado com Base na Requisição ---

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['token'])) {
    // Parte 2: Usuário clicou no link do email (Validar Token)
    $estado_atual = 'validar_token';
    $token_da_url = trim($_GET['token']);

} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['email'])) {
    // Parte 1: Usuário enviou o formulário de email (Processar Email)
    $estado_atual = 'processar_email';
    $email_post = trim($_POST['email']);

} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SESSION['reset_token_hash'], $_SESSION['reset_email'])) {
    // Parte 2: Usuário enviou o formulário de nova senha (Processar Reset)
    $estado_atual = 'processar_reset';

} elseif ($_SERVER['REQUEST_METHOD'] === 'GET' && !isset($_GET['token'])) {
    // Parte 1: Usuário acessou a página pela primeira vez (Mostrar Formulário de Email)
    $estado_atual = 'mostrar_form_email';

} else {
    // Estado inválido ou acesso incorreto
    $estado_atual = 'mostrar_mensagem';
    $mensagem_geral = "Acesso inválido.";
    $tipo_mensagem_geral = 'error';
}

// --- Lógica Principal Baseada no Estado ---

try { // Envolve a lógica principal em um try-catch para erros inesperados

    // == PARTE 1: SOLICITAÇÃO DE RECUPERAÇÃO ==
    if ($estado_atual === 'processar_email') {
        if (!empty($email_post) && filter_var($email_post, FILTER_VALIDATE_EMAIL)) {
            // Verificar se o email existe na tabela usuarios
            $sql_check_email = "SELECT id, nome FROM usuarios WHERE email = :email LIMIT 1";
            $stmt_check_email = $conn->prepare($sql_check_email);
            $stmt_check_email->execute([':email' => $email_post]);
            $usuario = $stmt_check_email->fetch(PDO::FETCH_ASSOC);

            if ($usuario) {
                // Email encontrado - Gerar Token, Hash e Salvar
                $token_original = bin2hex(random_bytes(32)); // Token seguro que vai no link
                $token_hash = hash('sha256', $token_original); // Hash que vai no banco
                $agora = new DateTime();
                $expira = clone $agora;
                $expira->modify("+" . $tempo_expiracao_token_horas . " hours");

                // Antes de inserir, remove tokens antigos para o mesmo email (evita acúmulo)
                 $sql_delete_old = "DELETE FROM password_resets WHERE email = :email";
                 $stmt_delete_old = $conn->prepare($sql_delete_old);
                 $stmt_delete_old->execute([':email' => $email_post]);

                // Inserir o novo token hash
                $sql_insert = "INSERT INTO password_resets (email, token_hash, expires_at) VALUES (:email, :token_hash, :expires_at)";
                $stmt_insert = $conn->prepare($sql_insert);
                $stmt_insert->execute([
                    ':email' => $email_post,
                    ':token_hash' => $token_hash,
                    ':expires_at' => $expira->format('Y-m-d H:i:s') // Formato padrão SQL DATETIME/TIMESTAMP
                ]);

                // Construir o Link de Reset
                $link_reset = $url_base_site . "/" . $arquivo_reset . "?token=" . $token_original;

                // Preparar e Enviar Email (USAR PHPMailer AQUI é RECOMENDADO)
                $assunto = "Recuperação de Senha - Seu Site RPG";
                $corpo_email = "Olá " . htmlspecialchars($usuario['nome']) . ",\n\n";
                $corpo_email .= "Você solicitou a recuperação de senha para sua conta em Seu Site RPG.\n";
                $corpo_email .= "Clique no link abaixo para definir uma nova senha:\n";
                $corpo_email .= $link_reset . "\n\n";
                $corpo_email .= "Este link expirará em " . $tempo_expiracao_token_horas . " hora(s).\n\n";
                $corpo_email .= "Se você não solicitou isso, ignore este email.\n\n";
                $corpo_email .= "Atenciosamente,\nEquipe Seu Site RPG";

                // ===============================================================
                // == INÍCIO: Bloco de Envio de Email (Exemplo com mail()) ==
                //    Substitua por PHPMailer para mais robustez/confiabilidade
                // ===============================================================
                $headers = 'From: seuemail@seusite.com' . "\r\n" . // MUDE AQUI
                           'Reply-To: seuemail@seusite.com' . "\r\n" . // MUDE AQUI
                           'X-Mailer: PHP/' . phpversion() . "\r\n" .
                           'Content-Type: text/plain; charset=UTF-8'; // Garante acentuação

                if (mail($email_post, $assunto, $corpo_email, $headers)) {
                    // Email enviado (pela função mail(), pode não ser 100% garantido)
                     $estado_atual = 'mostrar_mensagem';
                     $mensagem_geral = "Se o email '$email_post' estiver registrado, um link de recuperação foi enviado. Verifique sua caixa de entrada e spam.";
                     $tipo_mensagem_geral = 'success';
                } else {
                    // Erro no envio pela função mail()
                    error_log("Falha ao enviar email de recuperação para: $email_post usando mail()");
                     $estado_atual = 'mostrar_mensagem';
                     $mensagem_geral = "Ocorreu um problema ao tentar enviar o email de recuperação. Tente novamente mais tarde.";
                     $tipo_mensagem_geral = 'error';
                     // Poderia tentar mostrar o form de email de novo: $estado_atual = 'mostrar_form_email';
                }
                // ===============================================================
                // == FIM: Bloco de Envio de Email ==
                // ===============================================================

            } else {
                // Email não encontrado - Mostra a mesma mensagem genérica por segurança (evitar enumeração)
                $estado_atual = 'mostrar_mensagem';
                $mensagem_geral = "Se o email '$email_post' estiver registrado, um link de recuperação foi enviado. Verifique sua caixa de entrada e spam.";
                $tipo_mensagem_geral = 'success'; // Mostra sucesso mesmo se não achou
                 error_log("Tentativa de recuperação para email não registrado: $email_post");
            }

        } else {
            // Email inválido ou vazio
            $estado_atual = 'mostrar_form_email'; // Mostra o form de novo
            $mensagem_erro_validacao['email'] = "Por favor, insira um endereço de email válido.";
        }
    }

    // == PARTE 2: VALIDAÇÃO DO TOKEN E RESET DA SENHA ==
    elseif ($estado_atual === 'validar_token') {
        if (!empty($token_da_url) && strlen($token_da_url) === 64) { // Verifica se parece um token (64 chars hex)
             $token_hash_url = hash('sha256', $token_da_url); // Calcula o hash do token da URL

            // Buscar token hash no banco
            $sql_check = "SELECT email, expires_at FROM password_resets WHERE token_hash = :token_hash LIMIT 1";
            $stmt_check = $conn->prepare($sql_check);
            $stmt_check->execute([':token_hash' => $token_hash_url]);
            $reset_info = $stmt_check->fetch(PDO::FETCH_ASSOC);

            if ($reset_info) {
                // Token hash encontrado, verificar expiração
                $agora = new DateTime();
                $data_expiracao = new DateTime($reset_info['expires_at']);

                if ($agora < $data_expiracao) {
                    // Token válido e não expirado! Prepara para mostrar form de reset
                    $estado_atual = 'mostrar_form_reset';
                    $mostrar_formulario_reset = true; // Flag para exibir o form correto no HTML
                    // Guardar informações na sessão para usar no POST do reset
                    $_SESSION['reset_token_hash'] = $token_hash_url; // Guarda o HASH
                    $_SESSION['reset_email'] = $reset_info['email'];
                } else {
                    // Token expirado
                    $estado_atual = 'mostrar_mensagem';
                    $mensagem_geral = "Este link de recuperação de senha expirou.";
                    $tipo_mensagem_geral = 'error';
                    // Limpar token expirado do banco
                    $sql_delete = "DELETE FROM password_resets WHERE token_hash = :token_hash";
                    $stmt_delete = $conn->prepare($sql_delete);
                    $stmt_delete->execute([':token_hash' => $token_hash_url]);
                }
            } else {
                // Token hash não encontrado
                $estado_atual = 'mostrar_mensagem';
                $mensagem_geral = "Link de recuperação de senha inválido ou já utilizado.";
                $tipo_mensagem_geral = 'error';
            }
        } else {
             $estado_atual = 'mostrar_mensagem';
             $mensagem_geral = "Token de recuperação inválido ou mal formatado.";
             $tipo_mensagem_geral = 'error';
        }
    }

    elseif ($estado_atual === 'processar_reset') {
         $token_hash_sessao = $_SESSION['reset_token_hash'];
         $email_usuario = $_SESSION['reset_email'];
         $mostrar_formulario_reset = true; // Mantém o formulário visível se houver erro no POST

        // Validar senhas enviadas
        $nova_senha = $_POST['nova_senha'] ?? '';
        $confirmar_senha = $_POST['confirmar_senha'] ?? '';

        if (empty($nova_senha)) {
            $mensagem_erro_validacao['nova_senha'] = "O campo Nova Senha é obrigatório.";
        } elseif (strlen($nova_senha) < 8) { // Exemplo de regra adicional
             $mensagem_erro_validacao['nova_senha'] = "A senha deve ter pelo menos 8 caracteres.";
        }
        if (empty($confirmar_senha)) {
            $mensagem_erro_validacao['confirmar_senha'] = "O campo Confirmar Senha é obrigatório.";
        }
        if (!empty($nova_senha) && !empty($confirmar_senha) && $nova_senha !== $confirmar_senha) {
             $mensagem_erro_validacao['confirmar_senha'] = "As senhas não coincidem.";
        }

        // Se não houver erros de validação
        if (empty($mensagem_erro_validacao)) {
            // Buscar ID do usuário pelo email (obtido da sessão)
            $sql_get_user = "SELECT id FROM usuarios WHERE email = :email LIMIT 1";
            $stmt_get_user = $conn->prepare($sql_get_user);
            $stmt_get_user->execute([':email' => $email_usuario]);
            $usuario = $stmt_get_user->fetch(PDO::FETCH_ASSOC);

            if ($usuario) {
                $user_id = $usuario['id'];

                // Hash da nova senha
                $nova_senha_hash = password_hash($nova_senha, PASSWORD_DEFAULT);

                // Atualizar senha no banco de dados
                $sql_update = "UPDATE usuarios SET senha = :nova_senha_hash WHERE id = :user_id";
                $stmt_update = $conn->prepare($sql_update);
                $stmt_update->execute([
                    ':nova_senha_hash' => $nova_senha_hash,
                    ':user_id' => $user_id
                ]);

                // Deletar o token hash usado da tabela password_resets
                $sql_delete_token = "DELETE FROM password_resets WHERE token_hash = :token_hash";
                $stmt_delete_token = $conn->prepare($sql_delete_token);
                $stmt_delete_token->execute([':token_hash' => $token_hash_sessao]); // Usa o hash da sessão

                // Limpar dados da sessão de reset
                unset($_SESSION['reset_token_hash']);
                unset($_SESSION['reset_email']);

                // Definir mensagem de sucesso para a página de login e redirecionar
                $_SESSION['feedback_login'] = "Sua senha foi redefinida com sucesso! Faça o login com a nova senha.";
                header("Location: index.php");
                exit();

            } else {
                // Segurança extra: Usuário não encontrado para email associado a token válido.
                error_log("Erro crítico em recuperar_senha.php: Usuário não encontrado para email {$email_usuario} associado a token hash válido.");
                 $estado_atual = 'mostrar_mensagem';
                 $mensagem_geral = "Ocorreu um erro inesperado ao encontrar sua conta.";
                 $tipo_mensagem_geral = 'error';
                 $mostrar_formulario_reset = false;
            }
        }
        // Se houver erros de validação, o estado continua 'processar_reset',
        // mas $mostrar_formulario_reset=true e $mensagem_erro_validacao populado
        // farão o formulário ser exibido novamente com os erros.
    }

} catch (PDOException $e) {
    error_log("Erro PDO em recuperar_senha.php (Estado: $estado_atual): " . $e->getMessage());
    $estado_atual = 'mostrar_mensagem';
    $mensagem_geral = "Ocorreu um erro interno [DB]. Tente novamente mais tarde.";
    $tipo_mensagem_geral = 'error';
    $mostrar_formulario_reset = false; // Garante que nenhum form seja mostrado
} catch (Exception $e) { // Captura outros erros (DateTime, Mailer, etc.)
    error_log("Erro Geral em recuperar_senha.php (Estado: $estado_atual): " . $e->getMessage());
    $estado_atual = 'mostrar_mensagem';
    $mensagem_geral = "Ocorreu um erro inesperado [SYS]. Tente novamente mais tarde.";
    $tipo_mensagem_geral = 'error';
    $mostrar_formulario_reset = false; // Garante que nenhum form seja mostrado
}

?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Recuperar Senha - RPG Online</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&display=swap" rel="stylesheet">
    <style>
        /* ---> Seu CSS adaptado (similar ao da página de reset anterior) <--- */
         :root { /* ... suas variáveis CSS ... */ --bg-image-url: url('https://images.alphacoders.com/772/thumb-1920-772375.jpg'); --panel-bg: rgba(25, 28, 36, 0.88); --panel-border: rgba(255, 255, 255, 0.1); --text-primary: #e0e0e0; --text-secondary: #a0a0a0; --accent-color-1: #00e676; --accent-color-2: #ffab00; --accent-color-3: #2979ff; --danger-color: #ff5252; --success-color: var(--accent-color-1); --info-color: var(--accent-color-3); --disabled-color: #555e6d; --border-radius: 12px; --shadow: 0 6px 20px rgba(0, 0, 0, 0.4); }
         * { margin: 0; padding: 0; box-sizing: border-box; }
         body { background-image: var(--bg-image-url); background-size: cover; background-position: center; background-attachment: fixed; color: var(--text-primary); font-family: 'Roboto', sans-serif; margin: 0; padding: 25px; min-height: 100vh; background-color: #111; display: flex; align-items: center; justify-content: center; }

         .container-recovery { width: 100%; max-width: 450px; }
         .painel-recovery { background-color: var(--panel-bg); border-radius: var(--border-radius); padding: 35px 30px; box-shadow: var(--shadow); border: 1px solid var(--panel-border); backdrop-filter: blur(5px); display: flex; flex-direction: column; align-items: center; }
         .painel-recovery h2 { color: #fff; font-weight: 700; font-size: 1.6rem; margin-bottom: 25px; text-align: center; }

         /* Mensagens gerais (erro, sucesso, info) */
         .mensagem-geral { width: 100%; padding: 12px 15px; border-radius: 6px; border: 1px solid transparent; margin-bottom: 20px; font-weight: 500; text-align: center; font-size: 0.95rem; }
         .mensagem-geral.error { background-color: rgba(255, 82, 82, 0.1); border-color: var(--danger-color); color: var(--danger-color); }
         .mensagem-geral.success { background-color: rgba(0, 230, 118, 0.1); border-color: var(--success-color); color: var(--success-color); }
         .mensagem-geral.info { background-color: rgba(41, 121, 255, 0.1); border-color: var(--info-color); color: var(--info-color); } /* Cor para mensagens informativas */

         /* Formulários */
         .form-recovery { display: flex; flex-direction: column; gap: 18px; width: 100%; }
         .form-recovery .form-group { display: flex; flex-direction: column; gap: 8px; }
         .form-recovery label { font-weight: 500; color: var(--text-secondary); font-size: 0.9rem; }
         .form-recovery input[type="email"],
         .form-recovery input[type="password"] { width: 100%; padding: 14px 16px; border-radius: 8px; border: 1px solid var(--panel-border); background-color: rgba(0,0,0,0.3); color: var(--text-primary); font-size: 1rem; transition: border-color 0.2s ease, box-shadow 0.2s ease; }
         .form-recovery input:focus { outline: none; border-color: var(--accent-color-3); box-shadow: 0 0 0 3px rgba(41, 121, 255, 0.3); }
         .form-recovery button[type="submit"] { padding: 14px 20px; background: linear-gradient(45deg, var(--accent-color-3), #448aff); color: #fff; border: none; border-radius: 8px; font-weight: 700; font-size: 1.05rem; cursor: pointer; transition: all 0.2s ease; margin-top: 5px; }
         .form-recovery button[type="submit"]:hover { filter: brightness(1.15); box-shadow: 0 4px 12px rgba(41, 121, 255, 0.4); }
         /* Botão de reset com cor diferente */
         .form-recovery.reset-form button[type="submit"] { background: linear-gradient(45deg, var(--accent-color-1), #69f0ae); color: #111; }
          .form-recovery.reset-form button[type="submit"]:hover { box-shadow: 0 4px 12px rgba(0, 230, 118, 0.4); }

         /* Mensagens de erro de validação dos campos */
         .erro-validacao { color: var(--danger-color); font-size: 0.85rem; font-weight: 500; margin-top: -5px; /* Aproxima do campo */}

         .link-voltar { display: block; margin-top: 25px; text-align: center; color: var(--text-secondary); font-size: 0.9rem; text-decoration: none; }
         .link-voltar:hover { color: var(--accent-color-3); text-decoration: underline; }

         @media (max-width: 500px) {
             body { align-items: flex-start; padding: 20px; }
             .container-recovery { max-width: 100%; }
             .painel-recovery { padding: 25px 20px; }
             .painel-recovery h2 { font-size: 1.4rem; }
         }
    </style>
</head>
<body>

    <div class="container-recovery">
        <div class="painel-recovery">

            <?php if ($estado_atual === 'mostrar_form_email' || ($estado_atual === 'processar_email' && !empty($mensagem_erro_validacao))): ?>
                <h2>Recuperar Senha</h2>
                <p style="color: var(--text-secondary); font-size: 0.95rem; text-align: center; margin-bottom: 25px;">
                    Digite seu email abaixo. Se ele estiver registrado, enviaremos um link para você redefinir sua senha.
                </p>

                 <?php // Mostra erro geral se houver (ex: falha no envio de email)
                 if ($mensagem_geral && $estado_atual !== 'mostrar_mensagem'): ?>
                     <div class="mensagem-geral <?= htmlspecialchars($tipo_mensagem_geral) ?>">
                         <?= htmlspecialchars($mensagem_geral) ?>
                     </div>
                 <?php endif; ?>

                <form class="form-recovery" method="POST" action="<?= $arquivo_reset ?>">
                    <div class="form-group">
                        <label for="email">Seu Email:</label>
                        <input type="email" id="email" name="email" placeholder="email@exemplo.com" required value="<?= htmlspecialchars($email_post ?? '') ?>">
                         <?php if(isset($mensagem_erro_validacao['email'])): ?>
                            <span class="erro-validacao"><?= htmlspecialchars($mensagem_erro_validacao['email']) ?></span>
                         <?php endif; ?>
                    </div>
                    <button type="submit">Enviar Link de Recuperação</button>
                </form>

            <?php elseif ($estado_atual === 'mostrar_form_reset' || ($estado_atual === 'processar_reset' && !empty($mensagem_erro_validacao))): ?>
                <h2>Redefinir Senha</h2>
                 <p style="color: var(--text-secondary); font-size: 0.95rem; text-align: center; margin-bottom: 25px;">
                     Olá! Digite sua nova senha abaixo.
                 </p>

                 <?php // Mostra erro geral se houver (ex: falha no BD ao salvar)
                  if ($mensagem_geral && $estado_atual !== 'mostrar_mensagem'): ?>
                     <div class="mensagem-geral <?= htmlspecialchars($tipo_mensagem_geral) ?>">
                         <?= htmlspecialchars($mensagem_geral) ?>
                     </div>
                  <?php endif; ?>

                <form class="form-recovery reset-form" method="POST" action="<?= $arquivo_reset ?>">
                    <?php /* Token e email estão na sessão, não precisa de campos ocultos */ ?>
                    <div class="form-group">
                        <label for="nova_senha">Nova Senha:</label>
                        <input type="password" id="nova_senha" name="nova_senha" required>
                        <?php if(isset($mensagem_erro_validacao['nova_senha'])): ?>
                            <span class="erro-validacao"><?= htmlspecialchars($mensagem_erro_validacao['nova_senha']) ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="form-group">
                        <label for="confirmar_senha">Confirmar Nova Senha:</label>
                        <input type="password" id="confirmar_senha" name="confirmar_senha" required>
                         <?php if(isset($mensagem_erro_validacao['confirmar_senha'])): ?>
                            <span class="erro-validacao"><?= htmlspecialchars($mensagem_erro_validacao['confirmar_senha']) ?></span>
                        <?php endif; ?>
                    </div>
                    <button type="submit">Salvar Nova Senha</button>
                </form>

             <?php elseif ($estado_atual === 'mostrar_mensagem'): ?>
                 <h2>Recuperação de Senha</h2>
                 <?php // Exibe a mensagem geral definida durante o processamento ?>
                 <?php if ($mensagem_geral): ?>
                    <div class="mensagem-geral <?= htmlspecialchars($tipo_mensagem_geral) ?>">
                        <?= htmlspecialchars($mensagem_geral) ?>
                    </div>
                 <?php else: ?>
                     <div class="mensagem-geral error">Ocorreu um erro inesperado.</div>
                 <?php endif; ?>
                 <a class="link-voltar" href="index.php">Voltar para o Login</a>

             <?php else: ?>
                  <h2>Recuperação de Senha</h2>
                  <div class="mensagem-geral error">Estado inválido encontrado.</div>
                  <a class="link-voltar" href="index.php">Voltar para o Login</a>
             <?php endif; ?>


            <?php // Link geral para voltar ao login, exceto se estiver mostrando o form de reset
             if ($estado_atual !== 'mostrar_form_reset' && !($estado_atual === 'processar_reset' && !empty($mensagem_erro_validacao))) : ?>
                 <a class="link-voltar" href="index.php">Voltar para o Login</a>
             <?php endif; ?>

        </div>
    </div>

</body>
</html>
