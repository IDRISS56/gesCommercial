<?php
// vente_bloquee.php – Page affichée à la place d'un "alert()" JS lorsque
// l'utilisateur ne peut pas accéder à l'écran de vente (aucune caisse
// ouverte pour sa boutique, compte non lié à une boutique, etc.)
if (!function_exists('renderVenteBloquee')) {
    function renderVenteBloquee($message) {
        ?>
        <!DOCTYPE html>
        <html lang="fr">
        <head>
<?php include "includes/pwa_head.php"; ?>

            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Vente indisponible</title>
            <style>
                * { box-sizing: border-box; }
                body {
                    font-family: 'Inter', Arial, sans-serif;
                    background: #f1f5f9;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    height: 100vh;
                    margin: 0;
                    padding: 20px;
                }
                .box {
                    background: #fff;
                    padding: 36px 32px;
                    border-radius: 14px;
                    box-shadow: 0 12px 40px rgba(0,0,0,.08);
                    width: 100%;
                    max-width: 400px;
                    text-align: center;
                }
                .box .icon {
                    width: 56px; height: 56px; border-radius: 50%;
                    background: #fef3c7; color: #f59e0b;
                    display: flex; align-items: center; justify-content: center;
                    font-size: 26px; margin: 0 auto 18px;
                }
                .box h3 { margin: 0 0 10px; font-size: 18px; color: #0f172a; }
                .box p { color: #475569; font-size: 14px; line-height: 1.5; margin: 0 0 24px; }
                .box a {
                    display: inline-block; padding: 11px 22px; border-radius: 8px;
                    background: #4f46e5; color: #fff; text-decoration: none;
                    font-weight: 600; font-size: 14px;
                }
                .box a:hover { background: #3730a3; }
            </style>
        </head>
        <body>
            <div class="box">
                <div class="icon">&#9888;</div>
                <h3>Vente indisponible</h3>
                <p><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></p>
                <a href="../caisse/journee">Aller à la gestion de caisse</a>
            </div>
        </body>
        </html>
        <?php
    }
}
