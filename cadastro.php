<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
session_start();

// ---- Redirect SE JÁ ESTIVER LOGADO ----
if (isset($_SESSION['user_id'])) {
    header("Location: home.php");
    exit();
}

$erro = null; // Para mensagens de erro

// --- Processamento do Formulário POST (LÓGICA INALTERADA, recebe 'raca' do hidden input) ---
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Validação básica (agora verifica o hidden input 'raca')
    if (empty($_POST["nome"]) || empty($_POST["login"]) || empty($_POST["email"]) || empty($_POST["senha"]) || empty($_POST["r_senha"]) || empty($_POST["raca"])) { // Verifica $_POST['raca']
        $erro = "Todos os campos (exceto foto) são obrigatórios, incluindo a seleção da raça.";
    } else {
        // Coleta e limpa dados
        $nome = trim($_POST["nome"]); $login = trim($_POST["login"]); $email = filter_var(trim($_POST["email"]), FILTER_VALIDATE_EMAIL);
        $senha_raw = $_POST["senha"]; $r_senha_raw = $_POST["r_senha"]; $raca = $_POST["raca"]; // Pega do hidden input
        $foto_final_db = "default.jpg";

        // Validações adicionais
        if (!$email) { $erro = "Formato de e-mail inválido."; }
        elseif (strlen($senha_raw) < 6) { $erro = "A senha deve ter pelo menos 6 caracteres."; }
        elseif ($senha_raw !== $r_senha_raw) { $erro = "As senhas digitadas não coincidem."; }
        else {
            $racas_validas = ['Sayajin', 'Humano', 'Android', 'Namekuseijin'];
            if (!in_array($raca, $racas_validas)) { // Valida o valor recebido do hidden input
                $erro = "Raça selecionada inválida.";
            } else {
                // Processa a foto (lógica inalterada)
                if (isset($_FILES["foto"]) && $_FILES["foto"]["error"] == UPLOAD_ERR_OK) { /* ... (bloco de upload inalterado) ... */ }
                elseif (isset($_FILES["foto"]) && $_FILES["foto"]["error"] != UPLOAD_ERR_NO_FILE) { $erro = "Erro no upload..."; /* Log mantido */ }

                // Continua para o banco se não houver erro
                if ($erro === null) {
                    try {
                        require_once 'conexao.php';
                        $sql_check = "SELECT id FROM usuarios WHERE login = :login OR email = :email LIMIT 1";
                        $stmt_check = $conn->prepare($sql_check); $stmt_check->execute([':login' => $login, ':email' => $email]);

                        if ($stmt_check->fetch()) {
                           $erro = "Login ou E-mail já cadastrado."; /* Lógica de unlink da foto mantida */
                        } else {
                           // (Lógica de hash, insert no DB, redirect INALTERADA)
                           // ... usa $raca vindo do $_POST['raca'] (hidden input) ...
                            $senha_hash = password_hash($senha_raw, PASSWORD_DEFAULT); $pontos_iniciais = 1000; $zeni_inicial = 1000;
                            $sql_insert = "INSERT INTO usuarios (nome, login, email, senha, raca, foto, pontos, zeni) VALUES (:nome, :login, :email, :senha, :raca, :foto, :pontos, :zeni)";
                            $stmt_insert = $conn->prepare($sql_insert);
                            $params_insert = [ ':nome' => $nome, ':login' => $login, ':email' => $email, ':senha' => $senha_hash, ':raca' => $raca, ':foto' => $foto_final_db, ':pontos' => $pontos_iniciais, ':zeni' => $zeni_inicial ];
                            if ($stmt_insert->execute($params_insert)) { $_SESSION['feedback_login'] = "..."; header("Location: index.php"); exit(); } else { /* Throw exception, unlink foto */ }
                        }
                    } catch (PDOException $e) { /* Trata erro PDO, unlink foto */ }
                      catch (Exception $ex) { /* Trata erro geral, unlink foto */ }
                }
            }
        }
    }
}
// --- Fim do Processamento POST ---

// Prepara feedback final
$mensagem_final_feedback = "";
if ($erro) { $mensagem_final_feedback = "<p class='registration-message registration-error-message'>" . htmlspecialchars($erro) . "</p>"; }

// --- Descrições das raças (Usadas para gerar os cards) ---
$descricoes_racas = [
    'Sayajin' => 'Foco em força bruta, menos utilidades. Ideal para 1v1.',
    'Namekuseijin' => '"Suporte", cura, recuperação HP/KI, obtém info dos inimigos.',
    'Humano' => 'Supera limites, extrai info dos alvos, combate versátil.',
    'Android' => 'Versátil, habilidades únicas, passivas eficientes, dano e proteção.'
];
// Opcional: Ícones para cada raça (exemplo com placeholders Font Awesome - requer setup)
// $icones_racas = [ 'Sayajin' => 'fa-fist-raised', 'Namekuseijin' => 'fa-heart', 'Humano' => 'fa-user', 'Android' => 'fa-robot' ];
// Ou URLs de imagens pequenas:
$icones_racas = [
    'Sayajin' => 'https://i.pinimg.com/736x/b1/01/42/b101423c0ec05cdcffddf666487aa02b.jpg', // Exemplo placeholder
    'Namekuseijin' => 'https://i.pinimg.com/736x/82/46/ae/8246ae702e266818f7295520e57b9add.jpg', // Exemplo placeholder
    'Humano' => 'https://th.bing.com/th/id/OIP.7yNmAM6tdjmgNQ_myWDZ_QHaJ9?w=901&h=1211&rs=1&pid=ImgDetMain', // Exemplo placeholder
    'Android' => 'https://th.bing.com/th/id/R.d1ef05cd7d60d375f359b47721ca5332?rik=eMx1ziwr9NGzsg&pid=ImgRaw&r=0' // Exemplo placeholder
];


?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cadastro - DBZ World</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Bangers&family=Roboto:wght@400;500;700&display=swap" rel="stylesheet">
    <style>
        /* --- Estilos DBZ Theme (Revisados) --- */
        :root {
            /* ... (Paleta de cores DBZ mantida) ... */
             --dbz-orange: #f97300; --dbz-orange-dark: #d95f00; --dbz-blue: #0062cc; --dbz-blue-light: #3b82f6; --dbz-gold: #ffc400; --dbz-gold-dark: #e6ac00; --dbz-red: #d82c0d; --dbz-green: #00e676; --dbz-green-dark: #00c853; --text-primary: #f0f0f0; --text-secondary: #b0b0b0; --panel-bg: rgba(15, 18, 26, 0.92); /* Mais opaco */ --panel-border: rgba(255, 196, 0, 0.4); --danger-color: #ff4d4d; --danger-bg: rgba(216, 44, 13, 0.8); --success-color: var(--dbz-green); --disabled-color: #555e6d;
             --border-radius: 12px; /* Mais arredondado */ --shadow: 0 8px 30px rgba(0, 0, 0, 0.7); /* Sombra mais forte */ --shadow-glow-gold: 0 0 18px rgba(255, 196, 0, 0.4); --shadow-glow-blue: 0 0 18px rgba(59, 130, 246, 0.5); --shadow-glow-green: 0 0 18px rgba(0, 230, 118, 0.5);
             --bg-image-url: url('https://images8.alphacoders.com/407/407371.jpg');
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        html { scroll-behavior: smooth; }
        body {
            background-image: var(--bg-image-url); background-size: cover; background-position: center; background-attachment: fixed; color: var(--text-primary); font-family: 'Roboto', sans-serif; margin: 0; padding: 40px 20px; /* Mais padding vertical */ min-height: 100vh; background-color: #0a0a10; display: flex; align-items: center; justify-content: center;
        }
        .container { /* Agora só contém a coluna central */
            width: 100%; max-width: 750px; /* Largura ajustada para coluna única */
        }
        .coluna-cadastro { /* Nome da classe alterado */
            background-color: var(--panel-bg); border-radius: var(--border-radius); padding: 40px; /* Mais padding */ box-shadow: var(--shadow), var(--shadow-glow-blue); /* Sombra e brilho azul */ border: 2px solid var(--dbz-blue); backdrop-filter: blur(8px); display: flex; flex-direction: column;
        }
        .coluna-cadastro h2 {
            font-family: 'Bangers', cursive; color: var(--dbz-orange); font-weight: 400; font-size: 3.8rem; /* Maior */ margin-bottom: 35px; text-align: center; flex-shrink: 0; letter-spacing: 2px; text-shadow: 3px 3px 0px rgba(0,0,0,0.8), -1px -1px 0 var(--dbz-orange-dark), 1px -1px 0 var(--dbz-orange-dark), -1px 1px 0 var(--dbz-orange-dark), 1px 1px 0 var(--dbz-orange-dark);
        }
        .registration-message { /* Estilo de mensagem de erro mantido e refinado */
             width: 100%; margin: 0 0 30px 0; padding: 15px 20px; border-radius: 8px; text-align: center; font-weight: 700; font-size: 1rem; line-height: 1.6; border: 1px solid; text-shadow: 1px 1px 1px rgba(0,0,0,0.3);
         }
        .registration-error-message { color: white; background-color: var(--danger-bg); border-color: var(--dbz-red); /* Borda vermelha */ }
        .registration-error-message::before { content: "⚠️ "; } /* Ícone diferente */

        /* Estilos do Formulário */
        .form-cadastro { display: flex; flex-direction: column; gap: 22px; width: 100%; }
        .form-cadastro .form-group { display: flex; flex-direction: column; gap: 8px; }
        .form-cadastro label { font-weight: 700; color: var(--text-secondary); font-size: 0.95rem; text-transform: uppercase; letter-spacing: 0.8px; margin-bottom: 2px; }
        .form-cadastro input[type="text"], .form-cadastro input[type="email"], .form-cadastro input[type="password"] { /* Removido Select daqui */
            width: 100%; padding: 15px 18px; border-radius: 8px; border: 1px solid var(--panel-border); background-color: rgba(0,0,0,0.5); /* Fundo mais escuro */ color: var(--text-primary); font-size: 1.05rem; transition: border-color 0.2s ease, box-shadow 0.2s ease; }
        .form-cadastro input:focus { outline: none; border-color: var(--dbz-orange); box-shadow: 0 0 0 4px rgba(249, 115, 0, 0.5); }
        .form-cadastro input[type="file"] { /* Estilo input file refinado */
            padding: 12px; background-color: rgba(0,0,0,0.3); font-size: 0.95rem; cursor: pointer; border-radius: 8px; border: 1px solid var(--panel-border); }
        .form-cadastro input[type="file"]::file-selector-button, .form-cadastro input[type="file"]::-webkit-file-upload-button { /* Botão do input file */
            padding: 10px 18px; border: none; border-radius: 6px; background: linear-gradient(45deg, var(--dbz-blue), var(--dbz-blue-light)); color: #fff; font-weight: 700; cursor: pointer; transition: all 0.2s ease; margin-right: 15px; text-transform: uppercase; font-size: 0.85rem; letter-spacing: 0.5px; box-shadow: 0 2px 5px rgba(0,0,0,0.3); }
        .form-cadastro input[type="file"]::file-selector-button:hover, .form-cadastro input[type="file"]::-webkit-file-upload-button:hover { filter: brightness(1.15); transform: translateY(-1px); box-shadow: 0 4px 8px rgba(59, 130, 246, 0.4); }
        .form-cadastro .file-info { color: var(--text-secondary); font-size: 0.85rem; margin-top: 6px; display: block;}

        /* --- NOVA ÁREA: Seleção de Raça por Cards --- */
        .race-selection-area { margin-top: 15px; margin-bottom: 10px; }
        .race-selection-area > label { /* Label principal da área */
             display: block; margin-bottom: 15px; font-weight: 700; color: var(--text-primary); /* Texto primário */ font-size: 1.1rem; text-transform: uppercase; letter-spacing: 1px; text-align: center; padding-bottom: 10px; border-bottom: 1px dashed var(--panel-border);
         }
        .race-cards-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); /* Grid responsivo */
            gap: 20px; /* Espaço entre cards */
        }
        .race-card {
            background-color: rgba(30, 35, 45, 0.7); /* Fundo do card */
            border: 2px solid var(--panel-border);
            border-radius: var(--border-radius);
            padding: 20px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            align-items: center;
        }
        .race-card::before { /* Elemento para brilho sutil */
            content: ''; position: absolute; top: -50%; left: -50%; width: 200%; height: 200%; background: radial-gradient(circle, rgba(255, 196, 0, 0.1) 0%, rgba(255, 196, 0, 0) 70%); transition: opacity 0.3s ease; opacity: 0; z-index: 0;
        }
        .race-card:hover {
            transform: translateY(-5px) scale(1.02);
            border-color: var(--dbz-gold); /* Borda dourada no hover */
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.5), var(--shadow-glow-gold);
        }
        .race-card:hover::before { opacity: 1; }

        .race-card.selected { /* Estilo do card selecionado */
            border-color: var(--dbz-gold);
            background-color: rgba(40, 50, 70, 0.8); /* Fundo mais destacado */
            box-shadow: 0 0 0 4px var(--dbz-gold), 0 10px 30px rgba(0,0,0,0.6); /* Outline + Sombra */
            transform: scale(1.03); /* Levemente maior */
        }
         .race-card.selected::before { opacity: 1; } /* Garante brilho no selecionado */

        .race-card .raca-icon { /* Estilo para o ícone */
             width: 40px; height: 40px; margin-bottom: 15px; filter: invert(80%) sepia(100%) saturate(400%) hue-rotate(350deg) brightness(105%) contrast(100%); /* Tentativa de filtro dourado/laranja */ object-fit: contain; z-index: 1;
         }
         .race-card.selected .raca-icon { filter: none; /* Remove filtro quando selecionado */ }

        .race-card .raca-nome {
            font-family: 'Bangers', cursive; font-size: 1.8rem; color: var(--dbz-orange); margin-bottom: 10px; letter-spacing: 1px; z-index: 1;
        }
         .race-card.selected .raca-nome { color: var(--dbz-gold); } /* Nome dourado quando selecionado */

        .race-card .raca-descricao-texto {
            font-size: 0.9rem; color: var(--text-secondary); line-height: 1.6; z-index: 1; flex-grow: 1;
        }
         .race-card.selected .raca-descricao-texto { color: var(--text-primary); /* Descrição mais clara quando selecionado */ }
        /* --- FIM NOVA ÁREA --- */

        /* Botão Cadastrar Refinado */
        .form-cadastro button[type="submit"] {
             padding: 16px 30px; background: linear-gradient(45deg, var(--dbz-green), var(--dbz-green-dark)); color: #050505; /* Texto mais escuro */ border: none; border-radius: 10px; font-weight: 700; font-size: 1.25rem; text-transform: uppercase; letter-spacing: 1.2px; cursor: pointer; transition: all 0.25s ease; margin-top: 25px; /* Mais espaço acima */ box-shadow: 0 5px 15px rgba(0, 0, 0, 0.5);
         }
        .form-cadastro button[type="submit"]:hover:not(:disabled) { filter: brightness(1.2); transform: translateY(-3px) scale(1.02); box-shadow: 0 10px 20px var(--shadow-glow-green); }
        .form-cadastro button[type="submit"]:disabled { background: var(--disabled-color); color: #888; cursor: not-allowed; filter: none; box-shadow: none; opacity: 0.6; transform: none; }

        /* Link para Login Refinado */
        .login-link-container { text-align: center; margin-top: 35px; font-size: 1rem; }
        .login-link-container a { color: var(--dbz-blue-light); text-decoration: none; font-weight: 700; transition: color 0.2s ease; border-bottom: 1px dashed transparent; padding-bottom: 2px; }
        .login-link-container a:hover { color: var(--dbz-orange); border-bottom-color: var(--dbz-orange); text-decoration: none; }

        /* Validação de Senha (Inalterado) */
        .password-validation-message { font-size: 0.85rem; margin-top: 6px; height: 1.3em; display: block; font-weight: 500; }
        .password-match { color: var(--success-color); }
        .password-mismatch { color: var(--danger-color); }

        /* Responsividade para Layout de Coluna Única */
        @media (max-width: 600px) {
            body { padding: 25px 15px; }
            .container { max-width: 100%; }
            .coluna-cadastro { padding: 25px; border-width: 1px; }
            .coluna-cadastro h2 { font-size: 2.8rem; margin-bottom: 25px; }
            .form-cadastro { gap: 18px; }
            .form-cadastro input[type="text"], .form-cadastro input[type="email"], .form-cadastro input[type="password"] { padding: 14px 16px; font-size: 1rem; }
            .race-cards-container { grid-template-columns: 1fr; /* Uma coluna em telas pequenas */ gap: 15px; }
            .race-card { padding: 15px; }
            .race-card .raca-nome { font-size: 1.5rem; }
            .race-card .raca-descricao-texto { font-size: 0.85rem; }
            .form-cadastro button[type="submit"] { font-size: 1.1rem; padding: 15px 25px; margin-top: 20px; }
            .login-link-container { font-size: 0.9rem; margin-top: 30px; }
            .registration-message { font-size: 0.9rem; padding: 12px 15px; margin-bottom: 20px; }
        }
    </style>
</head>
<body>

    <div class="container">
        <div class="coluna-cadastro">
            <h2>Aliança de Guerreiros Z</h2>
            <?php echo $mensagem_final_feedback; ?>

            <form class="form-cadastro" method="POST" action="cadastro.php" enctype="multipart/form-data" id="cadastro-form" novalidate>
                <div class="form-group"> <label for="nome">Nome do Guerreiro:</label> <input type="text" id="nome" name="nome" placeholder="Ex: Gohan, Trunks..." required value="<?= htmlspecialchars($_POST['nome'] ?? '') ?>"> </div>
                 <div class="form-group"> <label for="login">Seu Login:</label> <input type="text" id="login" name="login" placeholder="Ex: gohan_ssj2" required value="<?= htmlspecialchars($_POST['login'] ?? '') ?>"> </div>
                 <div class="form-group"> <label for="email">E-mail (Corporação Cápsula):</label> <input type="email" id="email" name="email" placeholder="Ex: guerreiro@capsulecorp.com" required value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"> </div>
                 <div class="form-group"> <label for="senha">Senha Secreta:</label> <input type="password" id="senha" name="senha" placeholder="Mínimo 6 caracteres fortes" required minlength="6"> </div>
                 <div class="form-group"> <label for="r_senha">Confirmar Senha:</label> <input type="password" id="r_senha" name="r_senha" placeholder="Repita a senha para confirmar" required minlength="6"> <span id="password-match-message" class="password-validation-message"></span> </div>

                <div class="race-selection-area form-group">
                    <label>Escolha sua Raça:</label>
                    <div class="race-cards-container">
                        <?php foreach ($descricoes_racas as $raca_nome => $raca_desc): ?>
                            <?php
                                $icone_url = $icones_racas[$raca_nome] ?? ''; // Pega URL do ícone ou string vazia
                                $is_selected = (isset($_POST['raca']) && $_POST['raca'] == $raca_nome); // Verifica se estava selecionado antes (em caso de erro no form)
                            ?>
                            <div class="race-card <?= $is_selected ? 'selected' : '' ?>" data-value="<?= htmlspecialchars($raca_nome) ?>">
                                <?php if ($icone_url): ?>
                                    <img src="<?= htmlspecialchars($icone_url) ?>" alt="<?= htmlspecialchars($raca_nome) ?> Icon" class="raca-icon">
                                <?php endif; ?>
                                <h4 class="raca-nome"><?= htmlspecialchars($raca_nome == 'Android' ? 'Androide' : $raca_nome) // Corrige nome para display ?></h4>
                                <p class="raca-descricao-texto"><?= htmlspecialchars($raca_desc) ?></p>
                            </div>
                        <?php endforeach; ?>
                    </div>
                     <input type="hidden" id="raca" name="raca" value="<?= htmlspecialchars($_POST['raca'] ?? '') ?>">
                </div>
                <div class="form-group"> <label for="foto">Foto de Perfil (Scouter):</label> <input type="file" id="foto" name="foto" accept="image/png, image/jpeg, image/gif"> <small class="file-info">JPG, PNG ou GIF (máx 2MB). Padrão se não enviar.</small> </div>

                <button type="submit" id="btn-cadastrar" disabled>Elevar seu Ki! (Cadastrar)</button>
            </form>

            <div class="login-link-container">
                <span>Já tem seu Scouter? <a href="index.php">Faça Login!</a></span>
            </div>
        </div>
        </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const senhaInput = document.getElementById('senha');
            const rSenhaInput = document.getElementById('r_senha');
            const messageSpan = document.getElementById('password-match-message');
            const submitButton = document.getElementById('btn-cadastrar');
            const hiddenRacaInput = document.getElementById('raca'); // Pega o input hidden da raça
            const requiredTextFields = [ // Campos de texto/email obrigatórios
                 document.getElementById('nome'),
                 document.getElementById('login'),
                 document.getElementById('email')
             ];

             function checkAllRequiredFields() {
                // Verifica campos de texto/email
                 for (let field of requiredTextFields) {
                     if (!field || field.value.trim() === '') return false;
                 }
                 // Verifica formato de email
                 const emailField = document.getElementById('email');
                 if (emailField && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(emailField.value)) return false;
                 // Verifica se uma raça foi selecionada (checa o valor do input hidden)
                 if (!hiddenRacaInput || hiddenRacaInput.value.trim() === '') return false;

                 return true; // Todos os campos obrigatórios preenchidos E raça selecionada
             }

            function validatePasswordsAndForm() {
                if (!senhaInput || !rSenhaInput || !messageSpan || !submitButton || !hiddenRacaInput) { console.error("Elementos do form não encontrados."); return; }
                const senha = senhaInput.value; const rSenha = rSenhaInput.value; const minLength = 6;
                let lengthOk = senha.length >= minLength; let passwordsMatch = (senha === rSenha); let confirmationEntered = rSenha.length > 0;
                let allRequiredFilled = checkAllRequiredFields(); // Usa a função atualizada
                let isButtonDisabled = true;

                // Lógica de validação de senha (inalterada)
                if (!lengthOk && senha.length > 0) { messageSpan.textContent = 'Senha principal muito curta!'; messageSpan.className = 'password-validation-message password-mismatch'; }
                else if (confirmationEntered && !passwordsMatch) { messageSpan.textContent = 'As senhas não coincidem!'; messageSpan.className = 'password-validation-message password-mismatch'; }
                else if (lengthOk && confirmationEntered && passwordsMatch) { messageSpan.textContent = 'Senhas coincidem!'; messageSpan.className = 'password-validation-message password-match'; }
                else { messageSpan.textContent = ''; messageSpan.className = 'password-validation-message'; }

                // Habilita botão SÓ se senhas ok E todos os outros campos requeridos (incluindo raça) ok
                if (lengthOk && confirmationEntered && passwordsMatch && allRequiredFilled) { isButtonDisabled = false; }
                submitButton.disabled = isButtonDisabled;
            }

            // Listeners para campos de texto/senha
            if(senhaInput) senhaInput.addEventListener('input', validatePasswordsAndForm);
            if(rSenhaInput) rSenhaInput.addEventListener('input', validatePasswordsAndForm);
            requiredTextFields.forEach(field => { if(field) field.addEventListener('input', validatePasswordsAndForm); });

            // Listener será adicionado aos cards abaixo, mas chamamos a validação inicial
             validatePasswordsAndForm();
        });
    </script>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const cardsContainer = document.querySelector('.race-cards-container');
            const hiddenRacaInput = document.getElementById('raca');
            // Pega a função de validação global (se necessário revalidar ao clicar no card)
            const globalValidator = window.validatePasswordsAndForm || function() {}; // Pega a função do escopo anterior ou uma vazia

            if (cardsContainer && hiddenRacaInput) {
                const raceCards = cardsContainer.querySelectorAll('.race-card');

                // Verifica se já existe um valor pré-selecionado (útil em caso de erro no form)
                const initialValue = hiddenRacaInput.value;
                if (initialValue) {
                    raceCards.forEach(card => {
                        if (card.getAttribute('data-value') === initialValue) {
                            card.classList.add('selected');
                        }
                    });
                }


                cardsContainer.addEventListener('click', function(event) {
                    // Encontra o elemento .race-card clicado, mesmo se clicar num filho (nome, desc)
                    const clickedCard = event.target.closest('.race-card');

                    if (clickedCard) {
                        // Pega o valor da raça do atributo data-value
                        const selectedValue = clickedCard.getAttribute('data-value');

                        // Atualiza o input hidden
                        hiddenRacaInput.value = selectedValue;

                        // Atualiza visualmente os cards
                        raceCards.forEach(card => {
                            card.classList.remove('selected'); // Remove de todos
                        });
                        clickedCard.classList.add('selected'); // Adiciona ao clicado

                        // Revalida o formulário para habilitar/desabilitar o botão de submit
                         // Chama a função de validação global definida no script anterior
                         if (typeof validatePasswordsAndForm === 'function') {
                            validatePasswordsAndForm();
                         } else {
                            console.warn("Função validatePasswordsAndForm não encontrada no escopo global.");
                         }
                    }
                });
            } else {
                console.error("Container de cards ou input hidden da raça não encontrado.");
            }
        });
    </script>

</body>
</html>