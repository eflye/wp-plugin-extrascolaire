<?php
if (!defined('ABSPATH')) exit;

/** Logique métier du canal descendant mairie vers familles. */
class Psc_Messages {
    const CATEGORIES = array('information', 'urgent', 'cantine', 'periscolaire', 'evenement');
    const STATUSES = array('brouillon', 'programme', 'envoye');
    const TARGETS = array('all', 'ecole', 'service', 'familles');
    const SEEN_CHANNELS = array('portail', 'email', 'push');

    public static function get_categories() {
        return array(
            'information'  => array('label' => __('Information', 'periscolaire-registration'), 'bg' => '#EEF1F5', 'fg' => '#24405C'),
            'urgent'       => array('label' => __('Urgent', 'periscolaire-registration'), 'bg' => '#9E4A4A', 'fg' => '#FFFFFF'),
            'cantine'      => array('label' => __('Cantine', 'periscolaire-registration'), 'bg' => '#F5E7DC', 'fg' => '#8A4B25'),
            'periscolaire' => array('label' => __('Périscolaire', 'periscolaire-registration'), 'bg' => '#E7EEE8', 'fg' => '#33553C'),
            'evenement'    => array('label' => __('Événement', 'periscolaire-registration'), 'bg' => '#E08A5F', 'fg' => '#1A1A1A'),
        );
    }

    public static function allowed_html() {
        return array(
            'p' => array(), 'br' => array(), 'strong' => array(), 'em' => array(),
            'ul' => array(), 'ol' => array(), 'li' => array(), 'h3' => array(),
            'a' => array('href' => true, 'title' => true, 'target' => true),
        );
    }

    public static function get($id) {
        global $wpdb;
        $id = absint($id);
        if (!$id) return null;
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . psc_table('messages') . ' WHERE id = %d', $id));
    }

    public static function query($args = array()) {
        global $wpdb;
        $args = wp_parse_args($args, array('statut' => '', 'search' => '', 'orderby' => 'default', 'limit' => 50, 'offset' => 0));
        $where = array('1=1');
        $values = array();
        if (in_array($args['statut'], self::STATUSES, true)) {
            $where[] = 'm.statut = %s'; $values[] = $args['statut'];
        }
        if ($args['search'] !== '') {
            $where[] = 'm.titre LIKE %s'; $values[] = '%' . $wpdb->esc_like($args['search']) . '%';
        }
        $order = $args['orderby'] === 'date' ? 'm.date_envoi DESC, m.id DESC'
            : "FIELD(m.statut,'brouillon','programme','envoye'), COALESCE(m.date_envoi_prevue,m.date_envoi,m.created_at) DESC";
        $limit = max(1, min(200, (int) $args['limit']));
        $offset = max(0, (int) $args['offset']);
        $sql = 'SELECT m.*, COUNT(d.id) destinataires, SUM(d.vu_le IS NOT NULL) vus FROM ' . psc_table('messages') . ' m'
            . ' LEFT JOIN ' . psc_table('message_destinataires') . ' d ON d.message_id=m.id WHERE ' . implode(' AND ', $where)
            . " GROUP BY m.id ORDER BY $order LIMIT $limit OFFSET $offset";
        return $wpdb->get_results($values ? $wpdb->prepare($sql, $values) : $sql);
    }

    public static function save($data) {
        global $wpdb;
        $id = isset($data['id']) ? absint($data['id']) : 0;
        $existing = $id ? self::get($id) : null;
        if ($existing && $existing->statut === 'envoye') {
            $only_pin = array_diff(array_keys($data), array('id', 'epingle')) === array();
            if (!$only_pin) return new WP_Error('psc_message_sent', __('Un message envoyé ne peut plus être modifié.', 'periscolaire-registration'));
            $wpdb->update(psc_table('messages'), array('epingle' => empty($data['epingle']) ? 0 : 1, 'updated_at' => current_time('mysql')), array('id' => $id));
            return $id;
        }
        $title = mb_substr(sanitize_text_field(isset($data['titre']) ? $data['titre'] : ''), 0, 160);
        $body = wp_kses(isset($data['corps']) ? $data['corps'] : '', self::allowed_html());
        $category = isset($data['categorie']) && in_array($data['categorie'], self::CATEGORIES, true) ? $data['categorie'] : 'information';
        $status = isset($data['statut']) && in_array($data['statut'], self::STATUSES, true) ? $data['statut'] : 'brouillon';
        $target = isset($data['cible_type']) && in_array($data['cible_type'], self::TARGETS, true) ? $data['cible_type'] : 'all';
        if ($title === '' || trim(wp_strip_all_tags($body)) === '') return new WP_Error('psc_message_required', __('Le titre et le message sont obligatoires.', 'periscolaire-registration'));
        $target_value = self::normalize_target_value($target, isset($data['cible_valeur']) ? $data['cible_valeur'] : null);
        $channels = isset($data['canaux']) && is_array($data['canaux']) ? $data['canaux'] : array();
        $scheduled = !empty($data['date_envoi_prevue']) ? str_replace('T', ' ', sanitize_text_field($data['date_envoi_prevue'])) : null;
        if ($scheduled && strlen($scheduled) === 16) $scheduled .= ':00';
        if ($status === 'programme' && (!$scheduled || strtotime($scheduled) === false)) return new WP_Error('psc_message_schedule', __('Date de programmation invalide.', 'periscolaire-registration'));
        $attachment_id = !empty($data['piece_jointe_id']) ? absint($data['piece_jointe_id']) : 0;
        if ($attachment_id) {
            $mime = get_post_mime_type($attachment_id);
            $path = get_attached_file($attachment_id);
            if (!in_array($mime, array('application/pdf', 'image/jpeg', 'image/png'), true) || !$path || !is_file($path) || filesize($path) > 5 * MB_IN_BYTES) {
                return new WP_Error('psc_message_attachment', __('La pièce jointe doit être un PDF, JPG ou PNG de 5 Mo maximum.', 'periscolaire-registration'));
            }
        }
        $row = array(
            'titre' => $title, 'corps' => $body, 'categorie' => $category, 'statut' => $status,
            'cible_type' => $target, 'cible_valeur' => $target === 'all' ? null : wp_json_encode($target_value),
            'canaux' => wp_json_encode(array('portail' => true, 'email' => !empty($channels['email']), 'push' => false)),
            'piece_jointe_id' => $attachment_id ?: null,
            'epingle' => empty($data['epingle']) ? 0 : 1, 'accuse_requis' => empty($data['accuse_requis']) ? 0 : 1,
            'date_envoi_prevue' => $status === 'programme' ? $scheduled : null,
            'auteur_id' => $existing ? (int) $existing->auteur_id : get_current_user_id(), 'updated_at' => current_time('mysql'),
        );
        if ($existing) {
            $ok = $wpdb->update(psc_table('messages'), $row, array('id' => $id));
        } else {
            $row['created_at'] = current_time('mysql');
            $ok = $wpdb->insert(psc_table('messages'), $row); $id = (int) $wpdb->insert_id;
            if ($ok !== false) self::log_action('creation', $id);
        }
        if ($ok === false) return new WP_Error('psc_message_db', __('Impossible d’enregistrer le message.', 'periscolaire-registration'));
        if ($status === 'programme') self::ensure_schedule_cron();
        return $id;
    }

    private static function normalize_target_value($type, $value) {
        if (is_string($value)) $value = json_decode($value, true);
        if (!is_array($value)) $value = array();
        if ($type === 'ecole') return array('ecole' => isset($value['ecole']) && $value['ecole'] === 'maternelle' ? 'maternelle' : 'elementaire');
        if ($type === 'service') {
            $valid = array('cantine', 'accueil_matin', 'accueil_soir');
            return array('service' => isset($value['service']) && in_array($value['service'], $valid, true) ? $value['service'] : 'cantine');
        }
        if ($type === 'familles') return array('family_ids' => array_values(array_unique(array_filter(array_map('absint', isset($value['family_ids']) ? (array) $value['family_ids'] : array())))));
        return array();
    }

    public static function resolve_targets($type, $value = null) {
        global $wpdb;
        if (!in_array($type, self::TARGETS, true)) return array();
        $value = self::normalize_target_value($type, $value);
        $parents = psc_table('parents'); $children = psc_table('children'); $enrol = psc_table('child_school_years');
        $year = Psc_School_Years::active();
        if (!$year) return array();
        $base = "SELECT DISTINCT p.id FROM $parents p INNER JOIN $children c ON c.parent_id=p.id INNER JOIN $enrol cy ON cy.child_id=c.id AND cy.school_year_id=%d WHERE p.active=1 AND c.statut='actif'";
        $args = array((int) $year->id);
        if ($type === 'ecole') {
            $classes = $value['ecole'] === 'maternelle' ? array('PS', 'MS', 'GS') : array('CP', 'CE1', 'CE2', 'CM1', 'CM2');
            $base .= ' AND cy.classe IN (' . implode(',', array_fill(0, count($classes), '%s')) . ')'; $args = array_merge($args, $classes);
        } elseif ($type === 'service') {
            $map = array('cantine' => 'CANT', 'accueil_matin' => 'GM', 'accueil_soir' => 'GS'); $code = $map[$value['service']];
            $pat = psc_table('pattern'); $exc = psc_table('exception');
            $base .= " AND (EXISTS (SELECT 1 FROM $pat pt WHERE pt.child_id=c.id AND pt.school_year=%s AND pt.service_code IN (%s,'FORF')) OR EXISTS (SELECT 1 FROM $exc ex WHERE ex.child_id=c.id AND ex.jour_date BETWEEN %s AND %s AND ex.value=1 AND ex.service_code IN (%s,'FORF')))";
            $args = array_merge($args, array($year->label, $code, $year->date_debut, $year->date_fin, $code));
        } elseif ($type === 'familles') {
            $ids = $value['family_ids']; if (!$ids) return array();
            $base .= ' AND p.id IN (' . implode(',', array_fill(0, count($ids), '%d')) . ')'; $args = array_merge($args, $ids);
        }
        return array_values(array_unique(array_map('intval', $wpdb->get_col($wpdb->prepare($base, $args)))));
    }

    public static function count_targets($type, $value = null) {
        global $wpdb;
        $ids = self::resolve_targets($type, $value);
        if (!$ids) return array('familles' => 0, 'enfants' => 0);
        $ph = implode(',', array_fill(0, count($ids), '%d'));
        $year = Psc_School_Years::active();
        $children = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(DISTINCT c.id) FROM ' . psc_table('children') . ' c INNER JOIN ' . psc_table('child_school_years') . " cy ON cy.child_id=c.id AND cy.school_year_id=%d WHERE c.parent_id IN ($ph) AND c.statut='actif'", array_merge(array((int) $year->id), $ids)));
        return array('familles' => count($ids), 'enfants' => $children);
    }

    public static function send($message_id) {
        global $wpdb;
        $wpdb->query('START TRANSACTION');
        $message = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . psc_table('messages') . ' WHERE id=%d FOR UPDATE', absint($message_id)));
        if (!$message) { $wpdb->query('ROLLBACK'); return new WP_Error('psc_message_missing', __('Message introuvable.', 'periscolaire-registration')); }
        if ($message->statut === 'envoye') { $wpdb->query('ROLLBACK'); return new WP_Error('psc_message_sent', __('Ce message a déjà été envoyé.', 'periscolaire-registration')); }
        $targets = self::resolve_targets($message->cible_type, json_decode((string) $message->cible_valeur, true));
        foreach ($targets as $family_id) {
            $wpdb->query($wpdb->prepare('INSERT IGNORE INTO ' . psc_table('message_destinataires') . ' (message_id,family_id,token) VALUES (%d,%d,%s)', (int) $message->id, $family_id, wp_generate_password(32, false, false)));
        }
        $updated = $wpdb->update(psc_table('messages'), array('statut' => 'envoye', 'date_envoi' => current_time('mysql'), 'updated_at' => current_time('mysql')), array('id' => (int) $message->id));
        if ($updated === false) { $wpdb->query('ROLLBACK'); return new WP_Error('psc_message_db', __('Envoi impossible.', 'periscolaire-registration')); }
        $wpdb->query('COMMIT');
        $channels = json_decode((string) $message->canaux, true);
        if (!empty($channels['email']) && $targets) wp_schedule_single_event(time() + 5, 'psc_send_message_emails', array((int) $message->id));
        self::log_action('envoi', (int) $message->id, array('destinataires' => count($targets)));
        return array('destinataires' => count($targets), 'emails' => 0, 'echecs' => 0);
    }

    public static function process_email_batch($message_id) {
        global $wpdb;
        $message = self::get($message_id); if (!$message || $message->statut !== 'envoye') return;
        $rows = $wpdb->get_results($wpdb->prepare('SELECT d.*,p.email FROM ' . psc_table('message_destinataires') . ' d LEFT JOIN ' . psc_table('parents') . ' p ON p.id=d.family_id WHERE d.message_id=%d AND d.email_statut=%s ORDER BY d.id LIMIT 50', $message_id, 'non_envoye'));
        foreach ($rows as $row) {
            $ok = is_email($row->email) && Psc_Mailer::send_family_message($message, $row);
            $wpdb->update(psc_table('message_destinataires'), array('email_statut' => $ok ? 'envoye' : 'echec', 'email_erreur' => $ok ? null : (is_email($row->email) ? 'Échec wp_mail' : 'Adresse invalide')), array('id' => (int) $row->id));
        }
        $remaining = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . psc_table('message_destinataires') . ' WHERE message_id=%d AND email_statut=%s', $message_id, 'non_envoye'));
        if ($remaining) wp_schedule_single_event(time() + 30, 'psc_send_message_emails', array((int) $message_id));
    }

    public static function send_scheduled() {
        global $wpdb;
        $ids = $wpdb->get_col($wpdb->prepare('SELECT id FROM ' . psc_table('messages') . ' WHERE statut=%s AND date_envoi_prevue<=%s', 'programme', current_time('mysql')));
        foreach ($ids as $id) self::send((int) $id);
    }

    public static function ensure_schedule_cron() {
        if (!wp_next_scheduled('psc_send_scheduled_messages')) wp_schedule_event(time() + MINUTE_IN_SECONDS, 'hourly', 'psc_send_scheduled_messages');
    }

    public static function ensure_crons() {
        self::ensure_schedule_cron();
        if (!wp_next_scheduled('psc_cleanup_message_receipts')) wp_schedule_event(time() + DAY_IN_SECONDS, 'daily', 'psc_cleanup_message_receipts');
    }

    /** Efface les traces de lecture un an après la fin de leur année scolaire. */
    public static function cleanup_receipts() {
        global $wpdb;
        $years = $wpdb->get_results($wpdb->prepare('SELECT date_debut,date_fin FROM ' . psc_table('school_years') . ' WHERE date_fin < %s', gmdate('Y-m-d', strtotime('-1 year'))));
        foreach ($years as $year) {
            $wpdb->query($wpdb->prepare('UPDATE ' . psc_table('message_destinataires') . ' d INNER JOIN ' . psc_table('messages') . ' m ON m.id=d.message_id SET d.vu_le=NULL,d.vu_canal=NULL,d.accuse_le=NULL WHERE m.date_envoi BETWEEN %s AND %s', $year->date_debut . ' 00:00:00', $year->date_fin . ' 23:59:59'));
        }
    }

    public static function mark_seen($message_id, $family_id, $channel) {
        global $wpdb;
        if (!in_array($channel, self::SEEN_CHANNELS, true)) return false;
        return $wpdb->query($wpdb->prepare('UPDATE ' . psc_table('message_destinataires') . ' SET vu_le=%s,vu_canal=%s WHERE message_id=%d AND family_id=%d AND vu_le IS NULL', current_time('mysql'), $channel, $message_id, $family_id)) !== false;
    }

    public static function mark_ack($message_id, $family_id) {
        global $wpdb;
        return $wpdb->query($wpdb->prepare('UPDATE ' . psc_table('message_destinataires') . ' SET accuse_le=COALESCE(accuse_le,%s) WHERE message_id=%d AND family_id=%d', current_time('mysql'), $message_id, $family_id)) !== false;
    }

    public static function get_stats($message_id) {
        global $wpdb;
        $rows = $wpdb->get_col($wpdb->prepare('SELECT TIMESTAMPDIFF(MINUTE,m.date_envoi,d.vu_le) FROM ' . psc_table('message_destinataires') . ' d INNER JOIN ' . psc_table('messages') . ' m ON m.id=d.message_id WHERE d.message_id=%d AND d.vu_le IS NOT NULL ORDER BY 1', $message_id));
        $total = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . psc_table('message_destinataires') . ' WHERE message_id=%d', $message_id));
        $seen = count($rows); $median = null;
        if ($seen) { $middle = (int) floor($seen / 2); $median = $seen % 2 ? (int) $rows[$middle] : (int) round(((int) $rows[$middle - 1] + (int) $rows[$middle]) / 2); }
        return array('total' => $total, 'vus' => $seen, 'non_lus' => $total - $seen, 'taux' => $total ? (int) round($seen * 100 / $total) : 0, 'delai_median_minutes' => $median);
    }

    public static function get_recipients($message_id, $filter = 'tous') {
        global $wpdb;
        $where = $filter === 'vus' ? ' AND d.vu_le IS NOT NULL' : ($filter === 'non_lus' ? ' AND d.vu_le IS NULL' : '');
        return $wpdb->get_results($wpdb->prepare('SELECT d.*,p.nom,p.prenom,p.email FROM ' . psc_table('message_destinataires') . ' d LEFT JOIN ' . psc_table('parents') . " p ON p.id=d.family_id WHERE d.message_id=%d$where ORDER BY p.nom,p.email", $message_id));
    }

    public static function unread_count_for_family($family_id) {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . psc_table('message_destinataires') . ' d INNER JOIN ' . psc_table('messages') . " m ON m.id=d.message_id WHERE d.family_id=%d AND m.statut='envoye' AND d.vu_le IS NULL", $family_id));
    }

    public static function for_family($family_id) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare('SELECT m.*,d.vu_le,d.vu_canal,d.accuse_le,d.email_statut,d.token FROM ' . psc_table('message_destinataires') . ' d INNER JOIN ' . psc_table('messages') . " m ON m.id=d.message_id WHERE d.family_id=%d AND m.statut='envoye' ORDER BY m.epingle DESC,m.date_envoi DESC", $family_id));
    }

    public static function recipient($message_id, $token) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . psc_table('message_destinataires') . ' WHERE message_id=%d AND token=%s', $message_id, $token));
    }

    public static function resend_unread($message_id) {
        global $wpdb;
        $message = self::get($message_id);
        $channels = $message ? json_decode((string) $message->canaux, true) : array();
        if (!$message || empty($channels['email'])) return 0;
        $count = $wpdb->query($wpdb->prepare("UPDATE " . psc_table('message_destinataires') . " SET email_statut='non_envoye',email_erreur=NULL WHERE message_id=%d AND vu_le IS NULL", $message_id));
        if ($count) wp_schedule_single_event(time() + 5, 'psc_send_message_emails', array((int) $message_id));
        self::log_action('relance', $message_id, array('destinataires' => (int) $count));
        return (int) $count;
    }

    public static function delete($message_id) {
        global $wpdb;
        self::log_action('suppression', $message_id);
        $wpdb->delete(psc_table('message_destinataires'), array('message_id' => absint($message_id)));
        return $wpdb->delete(psc_table('messages'), array('id' => absint($message_id))) !== false;
    }

    private static function log_action($action, $message_id, $extra = array()) {
        $author_id = get_current_user_id();
        if (!$author_id) { $message = self::get($message_id); $author_id = $message ? (int) $message->auteur_id : 0; }
        $entry = wp_json_encode(array_merge(array('action' => $action, 'message_id' => (int) $message_id, 'auteur_id' => $author_id, 'date' => current_time('mysql')), $extra)) . "\n";
        $path = psc_private_path('journal-messages.log');
        if ($path) @file_put_contents($path, $entry, FILE_APPEND | LOCK_EX); // phpcs:ignore WordPress.PHP.NoSilencedErrors
    }
}
