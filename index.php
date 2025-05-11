<?php
session_start();

// Exibe os erros (Mantenha para desenvolvimento, considere desativar display_errors em produção)
ini_set('display_errors', 1); // Mantenha 0 ou comente em produção
error_reporting(E_ALL);      // Mantenha E_ALL & ~E_NOTICE ou 0 em produção

// Inclui a conexão PDO
require_once('conexao.php'); // Garante que $conn (PDO) está definido

// --- Inicialização de Variáveis de Controle ---
$erro = null;                      // Para mensagens de erro de login
$mostrar_notas_apos_login = false; // Flag para controlar a exibição
$update_notice_html = '';        // Conteúdo HTML das notas a serem exibidas
// --- Fim Inicialização ---

// Verifica se o usuário já está logado (se sim, vai direto pra home)
if (isset($_SESSION['user_id'])) {
    header("Location: home.php"); // Redireciona para a home se já logado
    exit();
}

// --- Tratamento de Erros de Login (via Session após redirect) ---
if (isset($_SESSION['login_error'])) {
    $erro = $_SESSION['login_error']; // Pega o erro da sessão (se houver)
    unset($_SESSION['login_error']); // Limpa o erro da sessão para não mostrar de novo
}
// --- Fim Tratamento de Erros ---

// --- Processamento do Formulário de Login (Método POST) ---
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (!empty($_POST['usuario']) && !empty($_POST['senha'])) {

        // Case-Insensitive para o login
        $usuario_post_lower = strtolower(trim($_POST['usuario']));
        $senha_post = $_POST['senha'];

        try {
            // Busca usuário pelo login (case-insensitive)
            $sql = "SELECT id, nome, senha, login FROM usuarios WHERE LOWER(login) = :login_lower LIMIT 1";
            $stmt = $conn->prepare($sql);

            if ($stmt && $stmt->execute([':login_lower' => $usuario_post_lower])) {
                $usuario_bd = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($usuario_bd && password_verify($senha_post, $usuario_bd['senha'])) {
                    // --- SUCESSO NO LOGIN ---
                    session_regenerate_id(true); // Segurança
                    $_SESSION['user_id'] = $usuario_bd['id'];
                    $_SESSION['nome'] = $usuario_bd['nome'];
                    $_SESSION['login'] = $usuario_bd['login'];

                    // <<< MUDANÇA PRINCIPAL: Definir flag para mostrar notas >>>
                    $mostrar_notas_apos_login = true;

                    // Carrega o conteúdo das notas de atualização
                    $notas_file = 'notas_atualizacao.html'; // Arquivo com o HTML das notas
                    if (file_exists($notas_file)) {
                        $update_notice_html = file_get_contents($notas_file);
                        // Aplica classe para estilização do marcador (opcional)
                        $update_notice_html = str_replace('<li>', '<li class="dbz-bullet">', $update_notice_html);
                    } else {
                        // Fallback se o arquivo não for encontrado
                        $update_notice_html = "<p style='color: var(--text-secondary); text-align: center;'>Não foi possível carregar as notas de atualização.</p>";
                    }
                    // <<< FIM DA MUDANÇA >>>

                    // NÃO redireciona aqui, a página será renderizada no modo "notas" abaixo

                } else {
                    // Login ou Senha incorreta (usuário não encontrado OU senha errada)
                    $_SESSION['login_error'] = "Login ou Senha incorreta!";
                    header("Location: index.php"); // Redireciona para mostrar o erro
                    exit();
                }
            } else {
                // Erro na execução do statement PDO
                error_log("Erro ao executar statement de login (PDO): " . implode(":", $stmt->errorInfo()));
                $_SESSION['login_error'] = "Erro ao processar login [EXEC]. Tente novamente.";
                header("Location: index.php");
                exit();
            }
        } catch (PDOException $e) {
            // Erro geral de conexão ou query PDO
            error_log("Erro PDO no login: " . $e->getMessage());
            $_SESSION['login_error'] = "Erro interno do servidor [DB]. Tente novamente mais tarde.";
            header("Location: index.php");
            exit();
        }
    } else {
        // Campos não preenchidos
        $_SESSION['login_error'] = "Por favor, preencha o usuário e a senha.";
        header("Location: index.php");
        exit();
    }
} // Fim do if ($_SERVER['REQUEST_METHOD'] == 'POST')

// --- Carrega Novidades para a Sidebar (APENAS se NÃO for mostrar notas pós-login) ---
$novidades_html_sidebar = '';
if (!$mostrar_notas_apos_login) { // Só carrega se for exibir o layout normal de login
    $notas_file_sidebar = 'notas_atualizacao.html'; // Assume mesmo arquivo, pode ser diferente
    if (file_exists($notas_file_sidebar)) {
        $novidades_html_sidebar = file_get_contents($notas_file_sidebar);
        // Adaptação para o estilo DBZ (opcional): Trocar marcadores
        $novidades_html_sidebar = str_replace('<li>', '<li class="dbz-bullet">', $novidades_html_sidebar);
    } else {
        $novidades_html_sidebar = "<p style='color: var(--text-secondary); text-align: center;'>Não foi possível carregar as novidades.</p>";
    }
}
// --- Fim notas ---

?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo ($mostrar_notas_apos_login ? 'Atualizações - DBZ World' : 'Login - DBZ World'); ?></title> <?php /* Título dinâmico */ ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Bangers&family=Roboto:wght@400;500;700&display=swap" rel="stylesheet">
    <style>
        /* --- Estilos DBZ Theme (COPIE TODO SEU CSS ATUAL AQUI) --- */
        /* ... (Cole todo o CSS que você já tinha aqui) ... */
         :root {
             /* Paleta DBZ */
             --dbz-orange: #f97300; /* Laranja Gi Goku */
             --dbz-orange-dark: #d95f00;
             --dbz-blue: #0062cc;    /* Azul Gi Goku */
             --dbz-blue-light: #3b82f6;
             --dbz-gold: #ffc400;   /* Super Saiyan / Esferas */
             --dbz-gold-dark: #e6ac00;
             --dbz-red: #d82c0d;     /* Detalhes / Capsule Corp */
             --text-primary: #f0f0f0;  /* Texto claro */
             --text-secondary: #b0b0b0; /* Texto secundário */
             --panel-bg: rgba(15, 18, 26, 0.9); /* Fundo painel escuro quase opaco */
             --panel-border: rgba(255, 196, 0, 0.4); /* Borda dourada sutil */
             --danger-color: #ff4d4d; /* Vermelho erro */
             --success-color: #4ade80; /* Verde sucesso */

             /* Configs Gerais */
             --border-radius: 10px;
             --shadow: 0 8px 25px rgba(0, 0, 0, 0.6);
             --shadow-glow: 0 0 15px rgba(255, 196, 0, 0.3); /* Brilho dourado suave */
             --bg-image-url: url('https://wallpapercave.com/wp/wp5751015.jpg'); /* Cenário Rochoso DBZ */
         }
         * { margin: 0; padding: 0; box-sizing: border-box; }
         body {
             background-image: var(--bg-image-url);
             background-size: cover; background-position: center; background-attachment: fixed;
             color: var(--text-primary); font-family: 'Roboto', sans-serif;
             margin: 0; padding: 25px; min-height: 100vh;
             background-color: #0a0a10; display: flex; align-items: center; justify-content: center;
         }

         /* --- Estilos para o Layout Normal de Login (3 Colunas) --- */
         .container-login {
             width: 100%; max-width: 1050px; display: flex; justify-content: center; gap: 30px; align-items: stretch;
         }
         .coluna {
             background-color: var(--panel-bg); border-radius: var(--border-radius); padding: 35px;
             box-shadow: var(--shadow), var(--shadow-glow); border: 2px solid var(--panel-border);
             backdrop-filter: blur(6px); display: flex; flex-direction: column;
         }
         .coluna-central { width: 40%; min-width: 320px; border-color: var(--dbz-blue); box-shadow: var(--shadow), 0 0 15px rgba(59, 130, 246, 0.4); }
         .coluna-central h2 { /* Título Login */
             font-family: 'Bangers', cursive; color: var(--dbz-orange); font-weight: 400; font-size: 3.5rem;
             margin-bottom: 35px; text-align: center; flex-shrink: 0; letter-spacing: 2px;
             text-shadow: 2px 2px 0px rgba(0,0,0,0.7), -1px -1px 0 var(--dbz-orange-dark), 1px -1px 0 var(--dbz-orange-dark), -1px 1px 0 var(--dbz-orange-dark), 1px 1px 0 var(--dbz-orange-dark);
         }
         .form-login { display: flex; flex-direction: column; gap: 25px; width: 100%; margin-top: auto; margin-bottom: auto; }
         .form-login .form-group { display: flex; flex-direction: column; gap: 8px; }
         .form-login label { font-weight: 700; color: var(--text-secondary); font-size: 0.95rem; text-transform: uppercase; letter-spacing: 0.5px; }
         .form-login input[type="text"], .form-login input[type="password"] {
             width: 100%; padding: 15px 18px; border-radius: 6px; border: 1px solid var(--panel-border);
             background-color: rgba(0, 0, 0, 0.4); color: var(--text-primary); font-size: 1.05rem; transition: border-color 0.2s ease, box-shadow 0.2s ease;
         }
         .form-login input:focus { outline: none; border-color: var(--dbz-orange); box-shadow: 0 0 0 4px rgba(249, 115, 0, 0.4); }
         .form-login button[type="submit"] { /* Botão Entrar */
             padding: 16px 25px; background: linear-gradient(45deg, var(--dbz-orange), var(--dbz-gold)); color: #111;
             border: none; border-radius: 8px; font-weight: 700; font-size: 1.2rem; text-transform: uppercase;
             letter-spacing: 1px; cursor: pointer; transition: all 0.25s ease; margin-top: 15px; box-shadow: 0 4px 10px rgba(0, 0, 0, 0.4);
         }
         .form-login button[type="submit"]:hover { filter: brightness(1.2); transform: translateY(-3px); box-shadow: 0 8px 15px rgba(255, 150, 0, 0.5); }
         .login-message-base { font-weight: 500; text-align: center; font-size: 0.95rem; padding: 14px; border-radius: 6px; border: 1px solid; margin-bottom: 20px; flex-shrink: 0; }
         .login-error-message { color: white; background-color: rgba(216, 44, 13, 0.8); border-color: rgba(255, 255, 255, 0.5); font-weight: 700; text-shadow: 1px 1px 1px #000; }
         .login-error-message::before { content: "⚠️ "; }
         .login-feedback-message { color: #000; background-color: var(--success-color); border-color: rgba(0,0,0, 0.3); font-weight: 700; }
         .login-feedback-message::before { content: "✅ "; }
         .forgot-pw-link { color: var(--text-secondary); font-size: 0.9rem; text-align: center; margin-top: 15px; text-decoration: none; flex-shrink: 0; transition: color 0.2s ease; }
         .forgot-pw-link:hover { color: var(--dbz-blue-light); text-decoration: underline; }
         .coluna-lateral-esquerda { width: 25%; min-width: 200px; padding: 25px; gap: 20px; align-items: center; justify-content: center; border-color: var(--dbz-red); box-shadow: var(--shadow), 0 0 15px rgba(216, 44, 13, 0.4); }
         .nav-button {
             display: block; width: 100%; padding: 14px 20px; color: var(--text-primary); text-decoration: none; text-align: center;
             border-radius: 8px; border: 2px solid transparent; font-size: 1rem; font-weight: 700; text-transform: uppercase;
             letter-spacing: 0.8px; transition: all 0.25s ease; box-shadow: 0 3px 8px rgba(0,0,0, 0.3);
         }
         .nav-button.register { background-color: var(--dbz-orange); border-color: var(--dbz-orange-dark); color: #fff; }
         .nav-button.register:hover { background-color: var(--dbz-orange-dark); border-color: var(--dbz-orange); transform: scale(1.03); box-shadow: 0 5px 12px rgba(249, 115, 0, 0.5); }
         .nav-button.home { background-color: var(--dbz-blue); border-color: var(--dbz-blue-light); color: #fff; }
         .nav-button.home:hover { background-color: var(--dbz-blue-light); border-color: var(--dbz-blue); transform: scale(1.03); box-shadow: 0 5px 12px rgba(59, 130, 246, 0.5); }
         .coluna-novidades { width: 35%; min-width: 280px; padding: 30px; gap: 20px; border-color: var(--dbz-gold); box-shadow: var(--shadow), var(--shadow-glow); }
         .coluna-novidades h3 { /* Título Novidades */
             font-family: 'Bangers', cursive; color: var(--dbz-gold); font-weight: 400; font-size: 2.5rem; margin-bottom: 20px;
             text-align: center; padding-bottom: 15px; border-bottom: 2px dashed var(--panel-border); flex-shrink: 0; letter-spacing: 1px; text-shadow: 1px 1px 0px rgba(0,0,0,0.6);
         }
         .news-content-wrapper { max-height: 400px; overflow-y: auto; padding-right: 15px; margin-right: -15px; flex-grow: 1; scrollbar-width: thin; scrollbar-color: var(--dbz-gold-dark) rgba(0,0,0,0.2); }
         .news-content-wrapper h2 { font-size: 1.2rem; color: var(--dbz-blue-light); margin-top: 18px; margin-bottom: 10px; padding-bottom: 6px; border-bottom: 1px solid var(--panel-border); font-weight: 700; letter-spacing: 0.5px; }
         .news-content-wrapper h2:first-of-type { margin-top: 0; border-top: none; }
         .news-content-wrapper p { font-size: 0.95rem; color: var(--text-secondary); margin-bottom: 12px; line-height: 1.7; }
         .news-content-wrapper ul { list-style: none; padding-left: 0; margin-bottom: 18px; }
         .news-content-wrapper .dbz-bullet { font-size: 0.95rem; margin-bottom: 10px; padding-left: 25px; position: relative; line-height: 1.5; color: var(--text-primary); }
         .news-content-wrapper .dbz-bullet::before { content: '★'; color: var(--dbz-orange); font-size: 1.1em; position: absolute; left: 0; top: 1px; text-shadow: 0 0 3px var(--dbz-gold); }
         .news-content-wrapper ul ul { margin-top: 8px; margin-bottom: 10px; padding-left: 20px;}
         .news-content-wrapper ul ul .dbz-bullet::before { content: '☆'; font-size: 1em; color: var(--dbz-blue-light);}
         .news-content-wrapper b, .news-content-wrapper strong { color: var(--dbz-gold); font-weight: 700; }
         .news-content-wrapper hr { border: none; border-top: 1px solid var(--panel-border); margin: 20px 0; }
         .news-content-wrapper::-webkit-scrollbar { width: 8px; }
         .news-content-wrapper::-webkit-scrollbar-track { background: rgba(0,0,0,0.2); border-radius: 4px;}
         .news-content-wrapper::-webkit-scrollbar-thumb { background-color: var(--dbz-gold-dark); border-radius: 4px; border: 1px solid rgba(0,0,0,0.3); }
         .news-content-wrapper::-webkit-scrollbar-thumb:hover { background-color: var(--dbz-gold); }

        /* --- Estilos para a Tela de Notas de Atualização --- */
        .update-notice-container { /* Container principal das notas */
            width: 100%;
            max-width: 800px; /* Largura máxima do painel de notas */
            margin: auto; /* Centraliza na tela */
            background-color: var(--panel-bg);
            border-radius: var(--border-radius);
            padding: 30px 40px;
            box-shadow: var(--shadow), 0 0 20px rgba(255, 196, 0, 0.5); /* Sombra + brilho dourado mais forte */
            border: 2px solid var(--dbz-gold); /* Borda Dourada */
            backdrop-filter: blur(8px); /* Mais blur */
            text-align: center; /* Centraliza título e botão */
            display: flex; /* Para controle interno */
            flex-direction: column;
        }
        .update-notice-container h2 { /* Título "Estado Atual do Jogo" */
             font-family: 'Bangers', cursive;
             color: var(--dbz-gold);
             font-weight: 400;
             font-size: 2.8rem; /* Tamanho do título */
             margin-bottom: 25px;
             text-align: center;
             padding-bottom: 15px;
             border-bottom: 2px dashed var(--panel-border); /* Borda tracejada */
             letter-spacing: 1px;
             text-shadow: 1px 1px 0px rgba(0,0,0,0.6);
             flex-shrink: 0; /* Não encolher */
        }
         .update-notice-content-area { /* Área de conteúdo rolável */
             text-align: left; /* Alinha o conteúdo das notas */
             max-height: 60vh; /* Altura máxima antes de rolar */
             overflow-y: auto; /* Adiciona scroll */
             margin-bottom: 30px; /* Espaço antes do botão */
             padding: 5px 15px 10px 5px; /* Padding interno (direita maior p/ scroll) */
             line-height: 1.6;
             font-size: 0.95rem;
             color: var(--text-secondary);
             /* Estilo scrollbar */
              scrollbar-width: thin;
              scrollbar-color: var(--dbz-gold-dark) rgba(0,0,0,0.2);
             flex-grow: 1; /* Tenta ocupar espaço vertical */
             min-height: 100px; /* Altura mínima */
         }
         /* Estilos Webkit para scrollbar */
         .update-notice-content-area::-webkit-scrollbar { width: 8px; }
         .update-notice-content-area::-webkit-scrollbar-track { background: rgba(0,0,0,0.2); border-radius: 4px;}
         .update-notice-content-area::-webkit-scrollbar-thumb { background-color: var(--dbz-gold-dark); border-radius: 4px; border: 1px solid rgba(0,0,0,0.3); }
         .update-notice-content-area::-webkit-scrollbar-thumb:hover { background-color: var(--dbz-gold); }
         /* Estilos para o conteúdo das notas (h4, ul, li, etc.) */
          .update-notice-content-area h4 {
                margin-top: 18px; margin-bottom: 10px; color: var(--dbz-blue-light); /* Azul claro */
                border-bottom: 1px solid var(--panel-border); padding-bottom: 6px; font-size: 1.15em; font-weight: 700; letter-spacing: 0.5px;
            }
           .update-notice-content-area h4:first-of-type { margin-top: 5px; }
           .update-notice-content-area ul { list-style: none; padding-left: 0; margin-bottom: 15px;}
           .update-notice-content-area li {
               margin-bottom: 12px; padding-left: 18px; border-left: 3px solid var(--dbz-blue); /* Borda azul */
               color: var(--text-primary); position: relative;
            }
            /* Usando marcador padrão + cor */
            .update-notice-content-area li::before { content: ''; display: none; } /* Remove marcador antigo se houver */
            .update-notice-content-area .dbz-bullet { /* Se usar a classe do HTML */
                 padding-left: 25px;
             }
             .update-notice-content-area .dbz-bullet::before {
                 content: '★'; color: var(--dbz-orange); font-size: 1.1em; position: absolute;
                 left: 0; top: 1px; text-shadow: 0 0 3px var(--dbz-gold);
             }

           .update-notice-content-area li strong { color: var(--dbz-orange); font-weight: bold; } /* Laranja para destaque */
           .update-notice-content-area .status-icon { margin-right: 7px; font-size: 1.1em; vertical-align: middle;} /* Ajuste dos ícones de status */
           .update-notice-content-area p { margin-bottom: 12px; color: var(--text-primary); line-height: 1.7;}

         .continue-button { /* Botão "Continuar" */
             display: inline-block; /* Para poder centralizar com text-align */
             padding: 14px 35px;
             background: linear-gradient(45deg, var(--dbz-blue), var(--dbz-blue-light)); /* Gradiente Azul */
             color: #fff; /* Texto branco */
             border: none;
             border-radius: 8px;
             font-weight: 700;
             font-size: 1.1rem;
             text-transform: uppercase;
             letter-spacing: 1px;
             cursor: pointer;
             transition: all 0.25s ease;
             box-shadow: 0 4px 10px rgba(0, 0, 0, 0.4);
             text-decoration: none; /* Remove sublinhado do link */
             margin-top: 10px; /* Espaço acima */
             flex-shrink: 0; /* Não encolher */
         }
         .continue-button:hover {
             filter: brightness(1.2);
             transform: translateY(-3px);
             box-shadow: 0 8px 15px rgba(59, 130, 246, 0.5); /* Sombra azul no hover */
         }


         /* --- Responsividade (Ajustes) --- */
         @media (max-width: 1000px) {
             body { align-items: flex-start; padding-top: 30px; }
             .container-login { flex-direction: column; align-items: center; width: 95%; max-width: 550px; gap: 25px;}
             .coluna { width: 100% !important; margin-bottom: 0; }
             .coluna-lateral-esquerda { order: 3; }
             .coluna-central { order: 1; }
             .coluna-novidades { order: 2; }
             .news-content-wrapper { max-height: 300px; }
             .coluna-central h2 { font-size: 3rem; }
             .coluna-novidades h3 { font-size: 2.2rem; }
              /* Ajuste para container de notas em telas menores */
              .update-notice-container { max-width: 95%; padding: 25px; }
              .update-notice-container h2 { font-size: 2.4rem; }
              .update-notice-content-area { max-height: 55vh; font-size: 0.9rem;}
         }
         @media (max-width: 600px) {
             body { padding: 15px; padding-top: 15px; } /* Menos padding */
             .container-login { gap: 15px; }
             .coluna { padding: 20px;}
             .coluna-central h2 { font-size: 2.5rem; margin-bottom: 25px; }
             .form-login { gap: 18px;}
             .form-login input[type="text"], .form-login input[type="password"] { padding: 13px 15px; font-size: 1rem;}
             .form-login button[type="submit"] { font-size: 1rem; padding: 14px 18px;}
             .nav-button { font-size: 0.9rem; padding: 12px 16px;}
             .coluna-novidades h3 { font-size: 2rem; }
             .news-content-wrapper { max-height: 250px; font-size: 0.9rem; }
             .login-message-base { padding: 11px; font-size: 0.85rem;}
              /* Ajuste para container de notas em telas muito pequenas */
              .update-notice-container { padding: 20px; }
              .update-notice-container h2 { font-size: 2.1rem; }
              .update-notice-content-area { max-height: 50vh; font-size: 0.85rem;}
              .continue-button { font-size: 1rem; padding: 12px 25px;}
         }

    </style>
</head>
<body>

    <?php // --- Renderização Condicional --- ?>

    <?php if (!$mostrar_notas_apos_login): ?>
        <?php // MODO 1: Exibir Tela de Login Normal ?>
        <div class="container-login"> <?php /* Novo container para layout de login */ ?>

            <div class="coluna coluna-lateral-esquerda">
                <a class="nav-button register" href="cadastro.php">Não tem conta? Cadastre-se!</a>
                <a class="nav-button home" href="index.php">Página Inicial</a>
                <?php // Poderia adicionar mais links aqui se necessário ?>
            </div>

            <div class="coluna coluna-central">
                <h2>Login</h2>

                <?php // Exibição de erro/feedback (se houver) ?>
                <?php if ($erro): ?>
                    <p class="login-message-base login-error-message"><?= htmlspecialchars($erro) ?></p>
                <?php endif; ?>
                <?php if (isset($_SESSION['feedback_login'])) : ?>
                    <p class="login-message-base login-feedback-message"><?= htmlspecialchars($_SESSION['feedback_login']) ?></p>
                    <?php unset($_SESSION['feedback_login']); ?>
                <?php endif; ?>

                <form class="form-login" method="POST" action="index.php">
                    <div class="form-group">
                        <label for="usuario">Usuário (Login):</label>
                        <input type="text" id="usuario" name="usuario" placeholder="Goku ou Vegeta?" required value="<?= isset($_POST['usuario']) ? htmlspecialchars($_POST['usuario']) : '' ?>">
                    </div>
                    <div class="form-group">
                        <label for="senha">Senha:</label>
                        <input type="password" id="senha" name="senha" placeholder="Sua senha secreta" required>
                    </div>
                    <button type="submit">Kamehameha! (Entrar)</button>
                    <a class="forgot-pw-link" href="recuperar_senha.php">Esqueceu sua senha? Chame o Shenlong!</a>
                </form>
            </div>

            <div class="coluna coluna-novidades">
                <h3>Radar do Dragão (Novidades)</h3>
                <div class="news-content-wrapper">
                    <?php echo $novidades_html_sidebar; // Exibe o conteúdo das notas na sidebar ?>
                </div>
            </div>

        </div> <?php /* Fim .container-login */ ?>

    <?php else: ?>
        <?php // MODO 2: Exibir Tela de Notas de Atualização Pós-Login ?>
        <div class="update-notice-container">
            <h2>Estado Atual do Jogo</h2>
            <div class="update-notice-content-area">
                <?php
                    // Insere o HTML das notas carregado anteriormente
                    // Certifique-se que notas_atualizacao.html contenha o HTML formatado
                    // como no exemplo da resposta anterior (com h4, ul, li, etc.)
                    echo $update_notice_html;
                ?>
            </div>
            <a href="home.php" class="continue-button">Continuar para o Jogo!</a>
        </div>

    <?php endif; ?>
    <?php // --- Fim Renderização Condicional --- ?>

</body>
</html>
<?php
// Limpa a conexão se necessário (geralmente não é preciso para scripts curtos)
// $conn = null;
?>