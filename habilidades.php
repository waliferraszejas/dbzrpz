<?php
session_start();
include("conexaohome.php");

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$id = $_SESSION['user_id'];

// Pega nível e tempo do usuário
$sqlUsuario = "SELECT nivel, tempo_sala FROM usuarios WHERE id = ?";
$stmtUsuario = $conn->prepare($sqlUsuario);
$stmtUsuario->bind_param("i", $id);
$stmtUsuario->execute();
$resultUsuario = $stmtUsuario->get_result();

if ($resultUsuario->num_rows === 0) {
    echo "Usuário não encontrado.";
    exit();
}

$usuario = $resultUsuario->fetch_assoc();
$nivel = $usuario['nivel'];
$tempo = $usuario['tempo_sala'];

// Pega as habilidades que ele ainda não aprendeu e que tem requisitos atendidos
$sql = "
SELECT hb.id, hb.nome, hb.nivel_requerido, hb.tempo_requirido
FROM habilidades_base hb
LEFT JOIN usuario_habilidades uh ON uh.habilidade_id = hb.id AND uh.usuario_id = ?
WHERE uh.habilidade_id IS NULL
AND hb.nivel_requerido <= ?
AND hb.tempo_requirido <= ?
";
$stmt = $conn->prepare($sql);
$stmt->bind_param("iii", $id, $nivel, $tempo);
$stmt->execute();
$result = $stmt->get_result();
?>

<h2>Habilidades disponíveis para aprender</h2>

<?php if ($result->num_rows === 0): ?>
    <p>Nenhuma habilidade disponível no momento.</p>
<?php else: ?>
    <form action="aprender_habilidades.php" method="post">
        <table border="1" cellpadding="10">
            <tr>
                <th>Nome</th>
                <th>Nível Requerido</th>
                <th>Tempo Requerido</th>
                <th>Ação</th>
            </tr>
            <?php while ($row = $result->fetch_assoc()): ?>
                <tr>
                    <td><?= htmlspecialchars($row['nome']) ?></td>
                    <td><?= $row['nivel_requerido'] ?></td>
                    <td><?= $row['tempo_requirido'] ?> min</td>
                    <td>
                        <button type="submit" name="habilidade_id" value="<?= $row['id'] ?>">Aprender</button>
                    </td>
                </tr>
            <?php endwhile; ?>
        </table>
    </form>
<?php endif; ?>
