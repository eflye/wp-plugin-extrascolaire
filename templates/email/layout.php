<?php if (!defined('ABSPATH')) exit; ?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta http-equiv="X-UA-Compatible" content="IE=edge">
<title><?php echo esc_html($title); ?></title>
</head>
<body style="margin:0;padding:0;background-color:#f0f2f5;font-family:Arial,Helvetica,sans-serif;-webkit-text-size-adjust:100%;">

<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f0f2f5;padding:32px 16px;">
<tr><td align="center">

  <!-- Conteneur principal -->
  <table width="600" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;width:100%;">

    <!-- En-tête -->
    <tr>
      <td style="background-color:#23478B;border-radius:6px 6px 0 0;padding:24px 32px;">
        <table width="100%" cellpadding="0" cellspacing="0" border="0">
          <tr>
            <td>
              <p style="margin:0;color:#ffffff;font-size:20px;font-weight:bold;letter-spacing:0.5px;">
                🏫 Périscolaire
              </p>
              <p style="margin:4px 0 0;color:#a8c4e8;font-size:13px;">
                <?php echo esc_html($site_name); ?>
              </p>
            </td>
          </tr>
        </table>
      </td>
    </tr>

    <!-- Corps -->
    <tr>
      <td style="background-color:#ffffff;padding:32px;border-left:1px solid #e1e5eb;border-right:1px solid #e1e5eb;">
        <?php echo $body_html; // Already escaped in each template ?>
      </td>
    </tr>

    <!-- Pied de page -->
    <tr>
      <td style="background-color:#f8f9fb;padding:16px 32px;border:1px solid #e1e5eb;border-top:none;border-radius:0 0 6px 6px;">
        <p style="margin:0;color:#9aa3b0;font-size:11px;text-align:center;line-height:1.6;">
          Ce message est envoyé automatiquement par <?php echo esc_html($site_name); ?> — merci de ne pas y répondre directement.<br>
          © <?php echo date('Y'); ?> <?php echo esc_html($site_name); ?>
        </p>
      </td>
    </tr>

  </table>
</td></tr>
</table>
</body>
</html>
