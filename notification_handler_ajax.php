<?php
// --- notification_handler_ajax.php ---
// Script para lidar com requisições AJAX de notificações (fetch, mark as read/delete, handle actions)

ini_set('display_errors', 0); // Mantenha 0 em produção para evitar exposição de erros sensíveis
error_reporting(E_ALL);

// Define o cabeçalho JSON para garantir a resposta correta
if (!headers_sent()) {
    header('Content-Type: application/json');
}

session_start();

// Resposta padrão em caso de falha inicial
$response = ['success' => false, 'error' => 'Erro inicial desconhecido no handler de notificações.'];

try {

    // Verifica se o usuário está autenticado pela sessão
    if (!isset($_SESSION['user_id'])) {
        $response = ['success' => false, 'error' => 'Usuário não autenticado.'];
        echo json_encode($response);
        exit; // Interrompe a execução se não autenticado
    }
    $user_id = (int)$_SESSION['user_id'];

    // Inclui o arquivo de conexão com o banco de dados.
    // Assume que 'conexao.php' está no mesmo diretório ou ajuste o path.
    // Este arquivo deve definir a variável $conn como um objeto PDO conectado.
    require_once("conexao.php");
    // Verifica se a conexão PDO foi estabelecida corretamente
    if (!isset($conn) || !($conn instanceof PDO)) {
        // Loga o erro crítico de conexão e lança uma exceção
        error_log("CRITICAL ERROR: PDO connection not available in notification_handler_ajax.php for user {$user_id}.");
        throw new RuntimeException("Falha ao estabelecer conexão PDO.");
    }

    // Tenta incluir funções globais de notificação se existirem em outro arquivo.
    // Ajuste o path 'functions/notification_functions.php' conforme a estrutura do seu projeto.
    @include_once('functions/notification_functions.php'); // Exemplo: Para add_system_notification

    // Obtém a ação solicitada (fetch, mark_notification_read, handle_notification_action)
    $action = $_GET['action'] ?? '';
    $notification_limit = 20; // Limite de notificações a serem buscadas por vez (para 'fetch')

    // Resposta padrão se a ação for inválida ou dados ausentes
    $response = ['success' => false, 'error' => 'Ação de notificação inválida ou dados ausentes na requisição.'];

    // --- Lógica para a Ação FETCH (Buscar Notificações) ---
    if ($action === 'fetch') {
        try {
            // Recebe o último ID de notificação que o cliente tem conhecimento.
            // O CLIENTE (JavaScript) É RESPONSÁVEL por armazenar o maior ID
            // recebido e enviá-lo de volta neste parâmetro em requisições subsequentes.
            // Se 0 for enviado, a busca começa do início.
            $last_notification_id = filter_input(INPUT_GET, 'last_notification_id', FILTER_VALIDATE_INT) ?: 0;
            $new_notifications = [];
            // Inicializa o ID da última notificação neste lote com o ID recebido do cliente.
            // Será atualizado para o maior ID das notificações RETORNADAS por esta busca.
            $current_last_notification_id = $last_notification_id;

            // SQL: Seleciona notificações para o usuário logado, com ID MAIOR que o último visto pelo cliente,
            // e que AINDA NÃO FORAM LIDAS (is_read = 0).
            // Como estamos DELETANDO notificações lidas com a ação 'mark_notification_read',
            // a condição 'is_read = 0' serve mais como uma garantia caso o DELETE falhe por algum motivo,
            // ou se você mudar o comportamento de 'mark_notification_read' para apenas atualizar 'is_read' = 1 no futuro.
            // A filtragem principal contra repetição de itens JÁ VISTOS (que não foram deletados) é pelo `id > :last_notification_id`.
            $sql_fetch_notif = "SELECT id, message_text, type, is_read, timestamp, related_data
                                FROM user_notifications
                                WHERE id > :last_notification_id AND user_id = :user_id AND is_read = 0
                                ORDER BY id ASC
                                LIMIT :limit";
            $stmt_fetch_notif = $conn->prepare($sql_fetch_notif);
            // Verifica se a preparação da query falhou
            if (!$stmt_fetch_notif) { throw new Exception("Falha na preparação da query Fetch Notif."); }

            // Parâmetros para a query preparada
            $notif_params = [
                ':last_notification_id' => $last_notification_id,
                ':user_id' => $user_id,
                ':limit' => $notification_limit // PDO::PARAM_INT é bom, mas o prepare geralmente infere para LIMIT
            ];
            // Executa a query
            $execute_notif_success = $stmt_fetch_notif->execute($notif_params);

            // Verifica se a execução falhou
            if (!$execute_notif_success) {
                // Loga o erro detalhado da execução da query
                $errorInfo = $stmt_fetch_notif->errorInfo();
                error_log("Erro PDO Execute (Fetch Notif) [{$user_id}] - SQLSTATE: {$errorInfo[0]} - Code: {$errorInfo[1]} - Message: {$errorInfo[2]}");
                throw new Exception("Falha na execução da query Fetch Notif.");
            }

            // Busca todos os resultados
            $new_notifications = $stmt_fetch_notif->fetchAll(PDO::FETCH_ASSOC);

            // Processa as notificações encontradas antes de enviar
            if (!empty($new_notifications)) {
                 // Atualiza current_last_notification_id para o ID da última notificação *neste lote retornado*.
                 // O cliente usará este valor na próxima requisição 'fetch'.
                 $current_last_notification_id = end($new_notifications)['id'];

                 foreach ($new_notifications as $key => $notif) {
                     // Proteção contra XSS no texto da mensagem
                     $new_notifications[$key]['message_text'] = htmlspecialchars($notif['message_text'] ?? '');

                     // Formatação do timestamp para exibição (hora:minuto)
                     try {
                         $dateTime = new DateTime($notif['timestamp']);
                         // Ajusta o fuso horário para o correto (ex: América/Sao_Paulo)
                         $dateTime->setTimezone(new DateTimeZone('America/Sao_Paulo'));
                         $new_notifications[$key]['timestamp_formatted'] = $dateTime->format('H:i');
                     } catch (Exception $dateEx) {
                         // Em caso de erro na data, exibe um placeholder e loga o erro
                         $new_notifications[$key]['timestamp_formatted'] = '??:??';
                         error_log("Erro ao formatar timestamp da notificação {$notif['id']} user {$user_id}: " . $dateEx->getMessage());
                     }

                     // Decodifica a string JSON em 'related_data' para um array PHP.
                     // Se for nulo ou string vazia, decodifica para null.
                     // O cliente (JavaScript) esperará related_data como um objeto/array JavaScript.
                     $new_notifications[$key]['related_data'] = json_decode($notif['related_data'] ?? 'null', true);
                     // Opcional: Verificar erros de JSON aqui, mas geralmente é tratado no JS ao usar.
                 }
            }

            // Prepara a resposta JSON de sucesso
            $response = [
                'success' => true,
                'notifications' => $new_notifications, // Array de notificações encontradas
                'last_notification_id' => (int)$current_last_notification_id // O maior ID retornado neste lote
            ];

        } catch (PDOException $e) {
            // Loga erros específicos do banco de dados durante a busca
            error_log("Erro PDO no notification_handler_ajax.php (fetch) [{$user_id}]: " . $e->getMessage() . " | SQLSTATE: " . $e->getCode());
            $response = ['success' => false, 'error' => 'Erro interno (DB) ao buscar notificações.'];
           } catch (Exception $ex) {
             // Loga erros gerais durante a busca
             error_log("Erro Geral no notification_handler_ajax.php (fetch) [{$user_id}]: " . $ex->getMessage());
             $response = ['success' => false, 'error' => 'Erro interno (Geral) ao buscar notificações.'];
        }

    // --- Lógica para a Ação MARK NOTIFICATION READ (AGORA DELETA) ---
    // Esta ação é chamada pelo cliente quando a notificação é descartada.
    // POR SUA SOLICITAÇÃO, ela agora DELETA a notificação em vez de apenas marcá-la como lida.
    } elseif ($action === 'mark_notification_read' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        try {
             // Obtém e valida o ID da notificação a ser marcada/deletada
             $notification_id = filter_input(INPUT_POST, 'notification_id', FILTER_VALIDATE_INT);
             if ($notification_id === false || $notification_id <= 0) {
                 $response = ['success' => false, 'error' => 'ID de notificação inválido para exclusão.'];
             } else {
                 // !!! ATENÇÃO: COMANDO DELETE ABAIXO !!!
                 // Este comando REMOVE PERMANENTEMENTE a notificação do banco de dados.
                 // Certifique-se de que este é o comportamento desejado.
                 // A abordagem padrão é UPDATE user_notifications SET is_read = 1 WHERE ...
                 $sql_delete_read = "DELETE FROM user_notifications WHERE id = :notification_id AND user_id = :user_id";
                 $stmt_delete_read = $conn->prepare($sql_delete_read);
                 if (!$stmt_delete_read) { throw new Exception("Falha na preparação da query Delete Notif."); }
                 // Parâmetros para a query DELETE
                 $params_delete = [
                     ':notification_id' => $notification_id,
                     ':user_id' => $user_id
                 ];
                 // Executa a query DELETE
                 $execute_success = $stmt_delete_read->execute($params_delete);

                 // Verifica se a execução foi bem-sucedida
                 if ($execute_success) {
                     // Verifica quantas linhas foram afetadas (deve ser 1 se a notificação existia e pertencia ao usuário)
                     $rowCount = $stmt_delete_read->rowCount();
                     if ($rowCount > 0) {
                         // Sucesso na exclusão
                         $response = ['success' => true, 'message' => 'Notificação excluída com sucesso.'];
                         // RESPONSABILIDADE DO CLIENTE (JavaScript):
                         // Ao receber success = true, o cliente DEVE remover/esconder visualmente
                         // esta notificação da exibição IMEDIATAMENTE para dar feedback rápido ao usuário.
                     } else {
                         // Nenhuma linha afetada - a notificação pode já ter sido deletada por outra requisição
                         // ou não pertence a este usuário.
                         $response = ['success' => false, 'error' => 'Notificação não encontrada para exclusão ou já foi excluída.'];
                         // Opcional: Logar tentativas de deletar notificações inexistentes/não pertencentes.
                         // error_log("Tentativa de DELETE sem efeito para NotifID [{$notification_id}] UserID [{$user_id}].");
                     }
                 } else {
                     // Erro na execução do DELETE
                     $errorInfo = $stmt_delete_read->errorInfo();
                     error_log("Erro PDO Execute (DELETE Notification Read) [{$user_id}], NotifID [{$notification_id}] - SQLSTATE: {$errorInfo[0]} - Code: {$errorInfo[1]} - Message: {$errorInfo[2]}");
                     $response = ['success' => false, 'error' => 'Erro ao excluir notificação.'];
                 }
             }
        } catch (PDOException $e) {
            // Loga erros específicos do banco de dados durante a exclusão
            error_log("Erro PDO no notification_handler_ajax.php (DELETE notification) [{$user_id}]: " . $e->getMessage());
            $response = ['success' => false, 'error' => 'Erro interno (DB) ao excluir notificação.'];
         } catch (Exception $ex) {
            // Loga erros gerais durante a exclusão
            error_log("Erro Geral no notification_handler_ajax.php (DELETE notification) [{$user_id}]: " . $ex->getMessage());
            $response = ['success' => false, 'error' => 'Erro interno (Geral) ao excluir notificação.'];
        }
    // --- Lógica para a Nova Ação: Handle Notification Specific Actions (e.g., Accept Invite) ---
    // Esta ação é chamada pelo cliente quando um botão de ação (como 'Aceitar Fusão') é clicado na notificação.
    // Ela executa a lógica de aplicação correspondente e depois remove a notificação.
    } elseif ($action === 'handle_notification_action' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        try {
            // Obtém e valida os dados necessários para a ação
            $notification_id = filter_input(INPUT_POST, 'notification_id', FILTER_VALIDATE_INT);
            $action_type = filter_input(INPUT_POST, 'action_type', FILTER_SANITIZE_STRING);

            // Verifica se os dados básicos da ação estão presentes
            if ($notification_id === false || $notification_id <= 0 || empty($action_type)) {
                $response = ['success' => false, 'error' => 'Dados de ação de notificação inválidos ou ausentes na requisição.'];
            } else {
                // 1. Buscar a notificação original e seus dados relacionados do banco de dados
                // Precisamos do 'related_data' para saber os detalhes específicos da ação (ex: qual convite, qual sessão de fusão).
                $sql_fetch_related = "SELECT related_data FROM user_notifications WHERE id = :notification_id AND user_id = :user_id";
                $stmt_fetch_related = $conn->prepare($sql_fetch_related);
                if (!$stmt_fetch_related) { throw new Exception("Falha na preparação da query Fetch related_data for action."); }
                $params_fetch_related = [':notification_id' => $notification_id, ':user_id' => $user_id];
                $execute_fetch_success = $stmt_fetch_related->execute($params_fetch_related);

                if (!$execute_fetch_success) {
                     // Loga erro de execução e lança exceção
                     $errorInfo = $stmt_fetch_related->errorInfo();
                     error_log("Erro PDO Execute (Fetch related_data for action) [{$user_id}], NotifID [{$notification_id}] - SQLSTATE: {$errorInfo[0]} - Code: {$errorInfo[1]} - Message: {$errorInfo[2]}");
                     throw new Exception("Erro ao buscar dados relacionados da notificação para ação.");
                }

                $notification_data = $stmt_fetch_related->fetch(PDO::FETCH_ASSOC);

                // Se a notificação não for encontrada, ela pode ter sido deletada recentemente ou não pertence ao usuário.
                // Neste caso, a ação não pode ser processada para esta notificação específica.
                if (!$notification_data) {
                    $response = ['success' => false, 'error' => 'Notificação não encontrada ou não pertence ao usuário (pode já ter sido processada ou excluída).'];
                } else {
                    // Decodifica a string JSON em 'related_data' para um array PHP.
                    $related_data = json_decode($notification_data['related_data'] ?? 'null', true);
                    // Verifica se houve erro na decodificação do JSON, a menos que related_data fosse nulo ou vazio.
                     if (($notification_data['related_data'] !== null && $notification_data['related_data'] !== '') && $related_data === null && json_last_error() !== JSON_ERROR_NONE) {
                         error_log("Erro ao decodificar related_data para NotifID [{$notification_id}] antes de ação user [{$user_id}]: " . json_last_error_msg() . ", Data: " . $notification_data['related_data']);
                         throw new Exception("Erro ao processar dados relacionados da notificação.");
                    }

                    // 2. Executa a lógica específica baseada no tipo de ação ($action_type)
                    switch ($action_type) {
                        case 'accept_fusion_invite':
                            // Lógica para aceitar um convite de fusão.
                            // O ID do convite da tabela `convites_fusao` é necessário para a atualização.
                            // O JavaScript do cliente DEVE enviar o convite_id no POST data.
                            // Para robustez, vamos obter do POST data.
                            $convite_id = filter_input(INPUT_POST, 'convite_id', FILTER_VALIDATE_INT);

                            // Outros dados úteis que podem estar no related_data ou ser enviados via POST:
                            // Precisamos do ID do convidante para notificar depois. Ele está no related_data original da notificação.
                             $inviter_user_id_from_related = $related_data['from_user_id'] ?? null;


                            // Verifica se o ID do convite é válido e foi recebido
                            if ($convite_id !== false && $convite_id > 0) {
                                // *** INÍCIO: LÓGICA REAL DE ACEITAR O CONVITE DE FUSÃO (MOVIDA DO SEU treinos.php) ***
                                // Usa o objeto PDO $conn disponível neste arquivo.

                                $acceptance_success = false; // Assume falha inicialmente
                                $acceptance_message = 'Erro desconhecido ao aceitar o convite.'; // Mensagem de erro padrão

                                try {
                                     // --- LÓGICA DE UPDATE NA TABELA convites_fusao ---
                                     // Atualiza o status do convite para 'aceito'.
                                     // WHERE: Garante que atualiza o convite correto (ID do convite),
                                     // que pertence ao usuário logado ($user_id, que é o convidado),
                                     // e que ainda está 'pendente'.
                                     $sql_update_invite = "UPDATE convites_fusao SET status = :status, data_atualizacao = CURRENT_TIMESTAMP WHERE id = :convite_id AND convidado_id = :user_id AND status = 'pendente'";
                                     $stmt_update_invite = $conn->prepare($sql_update_invite); // Usa $conn
                                     // Executa o UPDATE com os parâmetros
                                     $executed = $stmt_update_invite->execute([
                                         ':status' => 'aceito',
                                         ':convite_id' => $convite_id,
                                         ':user_id' => $user_id // Usa $user_id da sessão (o convidado)
                                     ]);

                                     // Verifica se a execução do UPDATE foi bem-sucedida
                                     if ($executed) {
                                         // Verifica quantas linhas foram afetadas. Se for > 0, o convite foi atualizado.
                                         $rowCount = $stmt_update_invite->rowCount();
                                         if ($rowCount > 0) {
                                              $acceptance_success = true;
                                              $acceptance_message = 'Convite de fusão aceito com sucesso!';

                                              // --- Lógica Adicional Pós-Aceitação (Opcional) ---
                                              // Exemplo: Notificar o usuário que enviou o convite que ele foi aceito.
                                              // Você precisa do ID do convidante (inviter_user_id_from_related).
                                              // A função add_system_notification deve estar disponível.
                                               if ($inviter_user_id_from_related && function_exists('add_system_notification')) {
                                                    try {
                                                        // Busca o nome do desafio para incluir na notificação ao convidante
                                                        $sql_desafio_nome = "SELECT d.nome_desafio FROM convites_fusao cf JOIN desafios d ON cf.desafio_id = d.id WHERE cf.id = :convite_id";
                                                        $stmt_desafio_nome = $conn->prepare($sql_desafio_nome);
                                                        $stmt_desafio_nome->execute([':convite_id' => $convite_id]);
                                                        $desafio_info = $stmt_desafio_nome->fetch(PDO::FETCH_ASSOC);
                                                        $desafio_nome = $desafio_info['nome_desafio'] ?? 'um desafio'; // Nome padrão se não encontrar

                                                        // Adiciona a notificação para o usuário convidante
                                                        // related_data para a notificação do convidante pode incluir ID do aceitante, ID do desafio, etc.
                                                        $related_data_to_inviter = json_encode([
                                                            'action_type' => 'view_accepted_invite', // Exemplo de ação para o convidante
                                                            'accepted_user_id' => $user_id,
                                                            'convite_id' => $convite_id,
                                                             'desafio_id' => $desafio_info['id'] ?? null // Se você buscar o ID do desafio
                                                        ]);
                                                        add_system_notification($conn, $inviter_user_id_from_related, "Seu convite para " . htmlspecialchars($desafio_nome) . " foi aceito!", 'info', $related_data_to_inviter);
                                                        error_log("Notificação enviada ao convidante {$inviter_user_id_from_related} apos aceitacao pelo user {$user_id} (convite {$convite_id})");

                                                    } catch (Exception $e_notif_inviter) {
                                                        error_log("Erro ao gerar notificação para convidante {$inviter_user_id_from_related} apos aceitacao: " . $e_notif_inviter->getMessage());
                                                        // Não impede a aceitação do convite, apenas loga o erro na notificação secundária.
                                                    }
                                               } else {
                                                   // Loga um alerta se a função de notificação não existir
                                                    if ($inviter_user_id_from_related) {
                                                        error_log("ALERTA: Função add_system_notification não encontrada ou inviter_user_id ausente ao tentar notificar convidante {$inviter_user_id_from_related} após aceitação.");
                                                    }
                                               }

                                              // Opcional: Lógica para iniciar a sessão de fusão, se todos aceitaram.
                                              // Isso dependeria da sua estrutura de sessões de fusão.

                                         } else {
                                             // Nenhuma linha afetada pelo UPDATE.
                                             // Isso acontece se o convite_id não existe para este user_id ou se já está 'aceito'/'recusado'.
                                             $acceptance_message = 'Não foi possível aceitar o convite (pode já ter sido respondido, cancelado ou não encontrado para você).';
                                             error_log("UPDATE convites_fusao rowCount 0 para convite {$convite_id} user {$user_id}. Status atual ou inexistente?");
                                         }
                                     } else {
                                         // Erro na execução do UPDATE
                                         $errorInfo = $stmt_update_invite->errorInfo();
                                         error_log("Erro PDO Execute (Update convites_fusao em handle_notification_action) [{$user_id}], ConviteID [{$convite_id}] - SQLSTATE: {$errorInfo[0]} - Code: {$errorInfo[1]} - Message: {$errorInfo[2]}");
                                         $acceptance_message = 'Erro interno ao atualizar o convite.';
                                     }

                                // *** FIM: LÓGICA REAL DE ACEITAR O CONVITE DE FUSÃO ***

                                } catch (Exception $e) {
                                    // Captura quaisquer outras exceções que ocorram dentro da sua lógica de aceitação
                                    $acceptance_message = 'Ocorreu um erro interno ao aceitar o convite.';
                                    error_log("Exceção durante a lógica de aceitação de convite (handle_notification_action) para ConviteID [{$convite_id}] user [{$user_id}]: " . $e->getMessage());
                                     $acceptance_success = false; // Garante que success seja falso em caso de exceção
                                }


                                // 3. Define a resposta JSON com base no resultado da lógica de aceitação.
                                if ($acceptance_success) {
                                    // Se a lógica de aceitação retornou sucesso
                                    $response = ['success' => true, 'message' => $acceptance_message];

                                    // 4. Deleta a notificação `user_notification` APÓS a ação bem-sucedida.
                                    // Isto remove a notificação da lista do usuário logado.
                                    // Esta parte já estava implementada, apenas garantimos que ocorra após o sucesso.
                                    $sql_delete_after_action = "DELETE FROM user_notifications WHERE id = :notification_id AND user_id = :user_id";
                                    $stmt_delete_after_action = $conn->prepare($sql_delete_after_action);
                                    if ($stmt_delete_after_action) {
                                        // Executa a deleção da notificação
                                        $delete_notif_executed = $stmt_delete_after_action->execute([':notification_id' => $notification_id, ':user_id' => $user_id]);
                                        if (!$delete_notif_executed) {
                                             // Loga se houver erro na deleção da notificação (mas a aceitação já ocorreu)
                                             $errorInfoDelete = $stmt_delete_after_action->errorInfo();
                                             error_log("Erro PDO Execute (DELETE Notif after action) [{$user_id}], NotifID [{$notification_id}] - SQLSTATE: {$errorInfoDelete[0]} - Code: {$errorInfoDelete[1]} - Message: {$errorInfoDelete[2]}");
                                        } else {
                                            // Loga sucesso na deleção da notificação se necessário
                                            // error_log("Notification [{$notification_id}] deleted after successful action for user [{$user_id}].");
                                        }
                                    } else {
                                        error_log("Falha ao preparar a instrução DELETE após a ação para NotifID [{$notification_id}] user [{$user_id}].");
                                    }

                                } else {
                                    // Se a lógica de aceitação retornou falha
                                    $response = ['success' => false, 'error' => $acceptance_message];
                                    // Opcional: NÃO deletar a notificação user_notification em caso de falha na ação,
                                    // para que o usuário veja o erro e a notificação não suma inesperadamente.
                                    // Por enquanto, não vamos deletar em caso de falha na lógica da ação.
                                }

                            } else {
                                // convite_id estava faltando ou era inválido no POST data.
                                // Isso indica um problema na requisição AJAX do cliente.
                                $response = ['success' => false, 'error' => 'ID do convite ausente ou inválido na requisição para aceitar.'];
                                error_log("convite_id ausente ou inválido no POST data para accept_fusion_invite action. UserID [{$user_id}], NotifID [{$notification_id}]");
                            }
                            break; // Fim do case 'accept_fusion_invite'

                        case 'reject_fusion_invite':
                            // --- INÍCIO: LÓGICA PARA RECUSAR CONVITE DE FUSÃO (MOVIDA E ADAPTADA) ---
                            // Similar ao aceitar, precisamos do convite_id.
                            $convite_id_reject = filter_input(INPUT_POST, 'convite_id', FILTER_VALIDATE_INT);
                             // Se o convite_id não veio no POST, tenta pegar do related_data
                             // $convite_id_reject = $convite_id_reject ?: ($related_data['convite_id'] ?? null);

                             if ($convite_id_reject !== false && $convite_id_reject > 0) {
                                 $rejection_success = false;
                                 $rejection_message = 'Erro desconhecido ao recusar o convite.';
                                 try {
                                      // Lógica para atualizar o status do convite na tabela convites_fusao para 'recusado'.
                                      // WHERE: Garante que atualiza o convite correto para o usuário logado e que ainda está pendente.
                                      $sql_reject = "UPDATE convites_fusao SET status = 'recusado', data_atualizacao = CURRENT_TIMESTAMP WHERE id = :convite_id AND convidado_id = :user_id AND status = 'pendente'";
                                      $stmt_reject = $conn->prepare($sql_reject);
                                      $executed_reject = $stmt_reject->execute([':convite_id' => $convite_id_reject, ':user_id' => $user_id]); // Usa $user_id da sessão

                                       if ($executed_reject) {
                                            $rowCountReject = $stmt_reject->rowCount();
                                           if ($rowCountReject > 0) {
                                                $rejection_success = true;
                                                $rejection_message = 'Convite de fusão rejeitado.';

                                                // Opcional: Notificar o usuário que enviou o convite que ele foi rejeitado.
                                                $inviter_user_id_from_related_reject = $related_data['from_user_id'] ?? null;
                                                if ($inviter_user_id_from_related_reject && function_exists('add_system_notification')) {
                                                     try {
                                                        // Busca o nome do desafio para incluir na notificação ao convidante
                                                        $sql_desafio_nome_reject = "SELECT d.nome_desafio FROM convites_fusao cf JOIN desafios d ON cf.desafio_id = d.id WHERE cf.id = :convite_id";
                                                        $stmt_desafio_nome_reject = $conn->prepare($sql_desafio_nome_reject);
                                                        $stmt_desafio_nome_reject->execute([':convite_id' => $convite_id_reject]);
                                                        $desafio_info_reject = $stmt_desafio_nome_reject->fetch(PDO::FETCH_ASSOC);
                                                        $desafio_nome_reject = $desafio_info_reject['nome_desafio'] ?? 'um desafio'; // Nome padrão se não encontrar

                                                        // Adiciona a notificação para o usuário convidante
                                                        $related_data_to_inviter_reject = json_encode([
                                                            'action_type' => 'view_rejected_invite', // Exemplo de ação para o convidante
                                                            'rejected_user_id' => $user_id,
                                                            'convite_id' => $convite_id_reject,
                                                            'desafio_id' => $desafio_info_reject['id'] ?? null
                                                        ]);

                                                         add_system_notification($conn, $inviter_user_id_from_related_reject, "Seu convite para " . htmlspecialchars($desafio_nome_reject) . " foi rejeitado.", 'info', $related_data_to_inviter_reject);
                                                         error_log("Notificação enviada ao convidante {$inviter_user_id_from_related_reject} apos rejeicao pelo user {$user_id} (convite {$convite_id_reject})");

                                                     } catch (Exception $e_notif_inviter_reject) {
                                                        error_log("Erro ao gerar notificação para convidante {$inviter_user_id_from_related_reject} apos rejeicao: " . $e_notif_inviter_reject->getMessage());
                                                     }
                                                } else {
                                                    if ($inviter_user_id_from_related_reject) {
                                                        error_log("ALERTA: Função add_system_notification não encontrada ou inviter_user_id ausente ao tentar notificar convidante {$inviter_user_id_from_related_reject} após rejeição.");
                                                    }
                                                }

                                           } else {
                                               // Nenhuma linha afetada - pode significar que o convite já foi respondido ou não existe.
                                               $rejection_message = 'Não foi possível rejeitar o convite (pode já ter sido respondido, cancelado ou não encontrado para você).';
                                               error_log("UPDATE convites_fusao rowCount 0 para recusa de convite {$convite_id_reject} user {$user_id}.");
                                           }
                                      } else {
                                          // Erro na execução do UPDATE
                                          $errorInfoReject = $stmt_reject->errorInfo();
                                          error_log("Erro PDO Execute (Update convites_fusao em handle_notification_action - Reject) [{$user_id}], ConviteID [{$convite_id_reject}] - SQLSTATE: {$errorInfoReject[0]} - Code: {$errorInfoReject[1]} - Message: {$errorInfoReject[2]}");
                                           $rejection_message = 'Erro interno ao atualizar o convite para rejeição.';
                                      }

                                 } catch (Exception $e) {
                                      $rejection_message = 'Ocorreu um erro interno ao recusar o convite.';
                                      error_log("Exceção durante a lógica de rejeição de convite (handle_notification_action) para ConviteID [{$convite_id_reject}] user [{$user_id}]: " . $e->getMessage());
                                      $rejection_success = false;
                                 }

                                 // Define a resposta JSON com base no resultado da rejeição
                                 if ($rejection_success) {
                                     $response = ['success' => true, 'message' => $rejection_message];
                                     // Deleta a notificação `user_notification` após a rejeição bem-sucedida.
                                     $sql_delete_after_action = "DELETE FROM user_notifications WHERE id = :notification_id AND user_id = :user_id";
                                     $stmt_delete_after_action = $conn->prepare($sql_delete_after_action);
                                      if ($stmt_delete_after_action) {
                                         $delete_notif_executed = $stmt_delete_after_action->execute([':notification_id' => $notification_id, ':user_id' => $user_id]);
                                          if (!$delete_notif_executed) {
                                               $errorInfoDelete = $stmt_delete_after_action->errorInfo();
                                               error_log("Erro PDO Execute (DELETE Notif after reject) [{$user_id}], NotifID [{$notification_id}] - SQLSTATE: {$errorInfoDelete[0]} - Code: {$errorInfoDelete[1]} - Message: {$errorInfoDelete[2]}");
                                          }
                                      } else {
                                          error_log("Falha ao preparar a instrução DELETE após a rejeição para NotifID [{$notification_id}] user [{$user_id}].");
                                      }
                                 } else {
                                      // Se a lógica de rejeição retornou falha
                                     $response = ['success' => false, 'error' => $rejection_message];
                                      // Não deleta a notificação user_notification em caso de falha na ação.
                                 }

                             } else {
                                 // convite_id faltando ou inválido no POST data para recusa.
                                 $response = ['success' => false, 'error' => 'ID do convite ausente ou inválido na requisição para recusar.'];
                                 error_log("convite_id ausente ou inválido no POST data para reject_fusion_invite action. UserID [{$user_id}], NotifID [{$notification_id}]");
                             }

                            break; // Fim do case 'reject_fusion_invite'


                        // Adicione mais cases para outros tipos de ação conforme necessário
                        /*
                        case 'some_other_action':
                            // Lógica para outra ação (ex: 'view_message')
                            // ...
                            $response = ['success' => true/false, 'message' => '...', 'error' => '...'];
                            // Opcional: deletar notificação após a ação
                             $sql_delete_after_action = "DELETE FROM user_notifications WHERE id = :notification_id AND user_id = :user_id";
                             $stmt_delete_after_action = $conn->prepare($sql_delete_after_action);
                              if ($stmt_delete_after_action) {
                                  $stmt_delete_after_action->execute([':notification_id' => $notification_id, ':user_id' => $user_id]);
                              }
                            break;
                        */

                        default:
                            // Lida com tipos de ação desconhecidos ou não implementados enviados pelo cliente.
                            // Isto pode indicar um erro no JavaScript do cliente ou uma requisição maliciosa.
                            $response = ['success' => false, 'error' => 'Tipo de ação de notificação desconhecido: ' . htmlspecialchars($action_type)];
                            error_log("Tipo de ação de notificação desconhecido recebido: [{$action_type}] para NotifID [{$notification_id}] do User [{$user_id}]");
                            break;
                    }
                }
            }

        } catch (PDOException $e) {
            // Loga erros específicos do banco de dados durante o manuseio da ação
            error_log("Erro PDO no notification_handler_ajax.php (handle_notification_action) [{$user_id}]: " . $e->getMessage() . " | SQLSTATE: " . $e->getCode());
            $response = ['success' => false, 'error' => 'Erro interno (DB) ao processar ação da notificação.'];
           } catch (Exception $ex) {
             // Loga erros gerais durante o manuseio da ação
             error_log("Erro Geral no notification_handler_ajax.php (handle_notification_action) [{$user_id}]: " . $ex->getMessage());
             $response = ['success' => false, 'error' => 'Erro interno (Geral) ao processar ação da notificação.'];
        }
    }

} catch(Exception $initError) {
    // Este bloco captura exceções que ocorrem antes mesmo de a ação específica ser identificada,
    // como falha na autenticação ou na conexão inicial com o DB.
    error_log("Erro Inicial CRÍTICO em notification_handler_ajax.php: " . $initError->getMessage() . " Stack: " . $initError->getTraceAsString());
    // Define a resposta apropriada dependendo se o erro ocorreu antes ou depois de verificar a autenticação
    if (!isset($_SESSION['user_id'])) {
        $response = ['success' => false, 'error' => 'Usuário não autenticado (verificação inicial falhou).'];
    } else {
        $response = ['success' => false, 'error' => 'Erro crítico na inicialização do handler de notificação.'];
    }
    if (!headers_sent()) { header('Content-Type: application/json'); }
} finally {
    // Garante que a conexão PDO seja fechada ao final da execução do script.
    // Verifica se a variável $conn existe e se é uma instância válida de PDO.
    if(isset($conn) && $conn instanceof PDO) {
        $conn = null;
    }
}

// Envia a resposta final em formato JSON para o cliente.
echo json_encode($response);
exit; // Encerra o script