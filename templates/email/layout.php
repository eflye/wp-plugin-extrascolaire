<?php if (!defined('ABSPATH')) exit; ?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta http-equiv="X-UA-Compatible" content="IE=edge">
<title><?php echo esc_html($title); ?></title>
</head>
<body style="margin:0;padding:0;background-color:#FBF7EC;font-family:Helvetica,Arial,sans-serif;-webkit-text-size-adjust:100%;">

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#FBF7EC;padding:32px 16px;">
<tr><td align="center">

  <!-- Conteneur principal : coins droits partout, pas d'ombre ni de dégradé -->
  <table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;width:100%;background-color:#ffffff;">

    <!-- En-tête -->
    <tr>
      <td style="background-color:#2D4A3E;padding:22px 32px;">
        <p style="margin:0;color:#E2A72B;font-family:Helvetica,Arial,sans-serif;font-size:11px;font-weight:bold;letter-spacing:0.28em;text-transform:uppercase;">
          Syndicat Intercommunal d'Intérêt Scolaire de Montgeroult – Courcelles
        </p>
      </td>
    </tr>

    <!-- Titre -->
    <?php $psc_email_title = preg_replace('/^\[[^\]]+\]\s*/', '', (string) $title); ?>
    <?php if ($psc_email_title !== ''): ?>
    <tr>
      <td style="background-color:#ffffff;padding:28px 32px 0;">
        <h1 style="margin:0;color:#2D4A3E;font-family:Georgia,'Times New Roman',serif;font-weight:bold;font-size:22px;line-height:1.3;">
          <?php echo esc_html($psc_email_title); ?>
        </h1>
      </td>
    </tr>
    <?php endif; ?>

    <!-- Corps -->
    <tr>
      <td style="background-color:#ffffff;padding:20px 32px 32px;font-family:Helvetica,Arial,sans-serif;color:#1A1A1A;font-size:14px;line-height:1.5;">
        <?php echo $body_html; // Already escaped in each template ?>
      </td>
    </tr>

    <!-- Pied de page -->
    <tr>
      <td style="background-color:#FBF7EC;padding:18px 32px;border-top:1px solid #E5DCC3;">
        <p style="margin:0;color:#8A837A;font-family:Helvetica,Arial,sans-serif;font-size:11px;line-height:1.6;">
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
