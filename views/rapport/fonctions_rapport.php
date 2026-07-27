<?php
// fonctions_rapport.php
require 'databases/database.php';

function e($str) {
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}

function fmt($n) {
    return number_format(floatval($n), 0, ',', ' ');
}

function fmtDec($n) {
    return number_format(floatval($n), 2, ',', ' ');
}

/**
 * Génère un tableau paginé avec AJAX
 */
function renderPaginatedTable($pdo, $sql, $countSql, $params, $page, $perPage = 20, $rowCallback = null) {
    $page = max(1, (int)$page);
    $stmt = $pdo->prepare($countSql);
    $stmt->execute($params);
    $total = (int)$stmt->fetchColumn();
    $totalPages = ceil($total / $perPage);
    if ($page > $totalPages && $totalPages > 0) $page = $totalPages;

    $offset = ($page - 1) * $perPage;
    $sql .= " LIMIT $offset, $perPage";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    ob_start();
    if (empty($rows)) {
        echo '<tr><td colspan="99" class="empty-cell">Aucune donnée.</td></tr>';
    } else {
        if ($rowCallback) {
            foreach ($rows as $row) {
                echo $rowCallback($row);
            }
        } else {
            // fallback : affichage simple des colonnes (on prend les clés de la première ligne)
            $cols = array_keys($rows[0] ?? []);
            foreach ($rows as $row) {
                echo '<tr>';
                foreach ($cols as $col) {
                    echo '<td>'.e($row[$col] ?? '').'</td>';
                }
                echo '</tr>';
            }
        }
    }
    $tableHtml = ob_get_clean();

    ob_start();
    if ($totalPages > 1): ?>
        <div class="d-flex flex-wrap align-items-center justify-content-between p-3 border-top bg-light">
            <span class="text-muted small">Affichage de <?= ($offset+1) ?> à <?= min($page*$perPage, $total) ?> sur <?= $total ?></span>
            <nav>
                <ul class="pagination pagination-sm mb-0">
                    <li class="page-item <?= ($page <= 1) ? 'disabled' : '' ?>">
                        <a class="page-link" href="#" data-page="<?= $page-1 ?>"><i class="bi bi-chevron-left"></i></a>
                    </li>
                    <?php
                    $start = max(1, $page - 2);
                    $end = min($totalPages, $page + 2);
                    if ($start > 1) {
                        echo '<li class="page-item"><a class="page-link" href="#" data-page="1">1</a></li>';
                        if ($start > 2) echo '<li class="page-item disabled"><span class="page-link">…</span></li>';
                    }
                    for ($i = $start; $i <= $end; $i++): ?>
                        <li class="page-item <?= ($i == $page) ? 'active' : '' ?>">
                            <a class="page-link" href="#" data-page="<?= $i ?>"><?= $i ?></a>
                        </li>
                    <?php endfor;
                    if ($end < $totalPages) {
                        if ($end < $totalPages - 1) echo '<li class="page-item disabled"><span class="page-link">…</span></li>';
                        echo '<li class="page-item"><a class="page-link" href="#" data-page="'.$totalPages.'">'.$totalPages.'</a></li>';
                    }
                    ?>
                    <li class="page-item <?= ($page >= $totalPages) ? 'disabled' : '' ?>">
                        <a class="page-link" href="#" data-page="<?= $page+1 ?>"><i class="bi bi-chevron-right"></i></a>
                    </li>
                </ul>
            </nav>
        </div>
    <?php endif;
    $paginationHtml = ob_get_clean();

    return [
        'table'   => $tableHtml,
        'pagination' => $paginationHtml,
        'total'   => $total,
        'page'    => $page,
        'totalPages' => $totalPages
    ];
}