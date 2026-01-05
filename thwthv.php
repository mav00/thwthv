<?php
/**
 * Plugin Name:       THWTHV
 * Description:       Grundgerüst für das THWTHV WordPress Plugin.
 * Version:           1.0.0
 * Author:            Matthias Verwold
 * License:           LGPL v2 or later
 * Text Domain:       thwthv
 */

// Direkten Zugriff verhindern.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Datenbank-Tabellen bei Aktivierung erstellen.
 */
function thwthv_install() {
	global $wpdb;
	$charset_collate = $wpdb->get_charset_collate();

	$table_dienste = $wpdb->prefix . 'thv_dienste';
	$sql_dienste = "CREATE TABLE $table_dienste (
		id mediumint(9) NOT NULL AUTO_INCREMENT,
		datum date NOT NULL,
		uhrzeit time NOT NULL,
		ort varchar(255) NOT NULL,
		PRIMARY KEY  (id)
	) $charset_collate;";

	$table_teilnehmer = $wpdb->prefix . 'thv_teilnehmer';
	$sql_teilnehmer = "CREATE TABLE $table_teilnehmer (
		id mediumint(9) NOT NULL AUTO_INCREMENT,
		dienst_id mediumint(9) NOT NULL,
		user_id bigint(20) NOT NULL,
		rolle varchar(5) DEFAULT 'N' NOT NULL,
		PRIMARY KEY  (id),
		KEY dienst_id (dienst_id)
	) $charset_collate;";

	$table_settings = $wpdb->prefix . 'thv_settings';
	$sql_settings = "CREATE TABLE $table_settings (
		id mediumint(9) NOT NULL AUTO_INCREMENT,
		type varchar(50) NOT NULL,
		value varchar(255) NOT NULL,
		PRIMARY KEY  (id)
	) $charset_collate;";

	require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
	dbDelta( $sql_dienste );
	dbDelta( $sql_teilnehmer );
	dbDelta( $sql_settings );
}
register_activation_hook( __FILE__, 'thwthv_install' );

/**
 * Tabellen-Check bei jedem Admin-Aufruf (Hilfsfunktion falls Aktivierung nicht lief).
 */
function thwthv_update_db_check() {
	global $wpdb;
	if ( $wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->prefix}thv_dienste'" ) != $wpdb->prefix . 'thv_dienste' ) {
		thwthv_install();
		return;
	}
	// Spalte 'rolle' prüfen, falls Tabelle schon existiert aber Spalte fehlt
	$row = $wpdb->get_results( "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE table_name = '{$wpdb->prefix}thv_teilnehmer' AND column_name = 'rolle'" );
	if ( empty( $row ) ) {
		thwthv_install();
	}
	// Tabelle 'settings' prüfen
	if ( $wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->prefix}thv_settings'" ) != $wpdb->prefix . 'thv_settings' ) {
		thwthv_install();
	}
}
add_action( 'admin_init', 'thwthv_update_db_check' );

/**
 * Prüft, ob der aktuelle Benutzer Admin ist.
 */
function thwthv_isAdmin() {
	if ( isset( $_GET['noadmin'] ) ) {
		return false;
	}
	

	global $wpdb;
	$table_settings = $wpdb->prefix . 'thv_settings';
	// Prüfen ob Tabelle existiert (verhindert Fehler bei früher Ausführung)
	if ( $wpdb->get_var( "SHOW TABLES LIKE '$table_settings'" ) != $table_settings ) {
		return false;
	}

	$admin_ids = $wpdb->get_col( $wpdb->prepare( "SELECT value FROM $table_settings WHERE type = %s", 'admin' ) );
	return in_array( get_current_user_id(), $admin_ids );
}

/**
 * Sendet eine Benachrichtigungs-E-Mail.
 */
function thwthv_send_notification( $dienst_id, $user_id ) {
	global $wpdb;
	
	$table_dienste = $wpdb->prefix . 'thv_dienste';
	$table_settings = $wpdb->prefix . 'thv_settings';

	$dienst = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table_dienste WHERE id = %d", $dienst_id ) );
	$user   = get_userdata( $user_id );
	$emails = $wpdb->get_col( $wpdb->prepare( "SELECT value FROM $table_settings WHERE type = %s", 'email' ) );

	if ( $dienst && $user && ! empty( $emails ) ) {
		$subject = 'THW Diensteintrag: Neuer Nutzereintrag in thv-Dienst';
		$message = sprintf(
			"Der Benutzer %s %s hat sich für folgenden Dienst eingetragen: ID: %d Datum: %s Zeit: %s Ort: %s",
			$user->first_name,
			$user->last_name,
			$dienst->id,
			date( 'd.m.Y', strtotime( $dienst->datum ) ),
			$dienst->uhrzeit,
			$dienst->ort
		);

		foreach ( $emails as $to ) {
			wp_mail( $to, $subject, $message );
		}
	}
}

/**
 * Sendet eine Benachrichtigungs-E-Mail bei Entfernung.
 */
function thwthv_send_deletion_notification( $dienst_id, $user_id ) {
	global $wpdb;
	$table_dienste = $wpdb->prefix . 'thv_dienste';
	$table_settings = $wpdb->prefix . 'thv_settings';

	$dienst = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table_dienste WHERE id = %d", $dienst_id ) );
	$user   = get_userdata( $user_id );
	$emails = $wpdb->get_col( $wpdb->prepare( "SELECT value FROM $table_settings WHERE type = %s", 'email' ) );

	if ( $dienst && $user && ! empty( $emails ) ) {
		$subject = 'THW Diensteintrag: Nutzer aus thv-Dienst entfernt';
		$message = sprintf(
			"Der Benutzer %s %s wurde aus folgendem Dienst entfernt: ID: %d Datum: %s Zeit: %s Ort: %s",
			$user->first_name,
			$user->last_name,
			$dienst->id,
			date( 'd.m.Y', strtotime( $dienst->datum ) ),
			$dienst->uhrzeit,
			$dienst->ort
		);

		foreach ( $emails as $to ) {
			wp_mail( $to, $subject, $message );
		}
	}
}

/**
 * Shortcode zur Ausgabe der Dienste: [thv_dienste]
 */
function thwthv_shortcode_dienste() {
	global $wpdb;
	$table_dienste = $wpdb->prefix . 'thv_dienste';
	$table_teilnehmer = $wpdb->prefix . 'thv_teilnehmer';
	$output = '';

	// Verarbeitung: Dienst löschen (Admin)
	if ( isset( $_POST['thwthv_delete_dienst'] ) && thwthv_isAdmin() ) {
		if ( isset( $_POST['thwthv_delete_nonce'] ) && wp_verify_nonce( $_POST['thwthv_delete_nonce'], 'thwthv_delete_dienst' ) ) {
			$del_dienst_id = intval( $_POST['thwthv_delete_dienst_id'] );
			$wpdb->delete( $table_dienste, array( 'id' => $del_dienst_id ) );
			$wpdb->delete( $table_teilnehmer, array( 'dienst_id' => $del_dienst_id ) );
			$output .= '<div style="background:#fff3cd;color:#856404;padding:10px;margin-bottom:15px;">Dienst gelöscht.</div>';
		}
	}

	// Verarbeitung: Rolle ändern (Admin)
	if ( isset( $_POST['thwthv_update_role'] ) && thwthv_isAdmin() ) {
		if ( isset( $_POST['thwthv_role_nonce'] ) && wp_verify_nonce( $_POST['thwthv_role_nonce'], 'thwthv_update_role' ) ) {
			$upd_dienst_id = intval( $_POST['thwthv_role_dienst'] );
			$upd_user_id   = intval( $_POST['thwthv_role_user'] );
			$new_role      = sanitize_text_field( $_POST['thwthv_role_value'] );

			if ( in_array( $new_role, array( 'GF', 'KF', 'H', 'N' ) ) ) {
				$wpdb->update( $table_teilnehmer, array( 'rolle' => $new_role ), array( 'dienst_id' => $upd_dienst_id, 'user_id' => $upd_user_id ) );
				$output .= '<div style="background:#d4edda;color:#155724;padding:10px;margin-bottom:15px;">Rolle aktualisiert.</div>';
			}
		}
	}

	// Verarbeitung: Teilnehmer entfernen (Admin)
	if ( isset( $_POST['thwthv_remove_participant'] ) && thwthv_isAdmin() ) {
		if ( isset( $_POST['thwthv_remove_nonce'] ) && wp_verify_nonce( $_POST['thwthv_remove_nonce'], 'thwthv_remove_participant' ) ) {
			$rem_dienst_id = intval( $_POST['thwthv_remove_participant_dienst'] );
			$rem_user_id   = intval( $_POST['thwthv_remove_participant_user'] );
			$wpdb->delete( $table_teilnehmer, array( 'dienst_id' => $rem_dienst_id, 'user_id' => $rem_user_id ) );
			thwthv_send_deletion_notification( $rem_dienst_id, $rem_user_id );
			$output .= '<div style="background:#fff3cd;color:#856404;padding:10px;margin-bottom:15px;">Teilnehmer entfernt.</div>';
		}
	}

	// Verarbeitung: Teilnehmer hinzufügen (Admin)
	if ( isset( $_POST['thwthv_add_participant'] ) && thwthv_isAdmin() ) {
		if ( isset( $_POST['thwthv_add_nonce'] ) && wp_verify_nonce( $_POST['thwthv_add_nonce'], 'thwthv_add_participant' ) ) {
			$add_dienst_id = intval( $_POST['thwthv_add_participant_dienst'] );
			$add_user_id   = intval( $_POST['thwthv_add_participant_user'] );

			if ( $add_dienst_id > 0 && $add_user_id > 0 ) {
				$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table_teilnehmer WHERE dienst_id = %d AND user_id = %d", $add_dienst_id, $add_user_id ) );
				if ( ! $exists ) {
					$wpdb->insert( $table_teilnehmer, array( 'dienst_id' => $add_dienst_id, 'user_id' => $add_user_id, 'rolle' => 'N' ) );
					thwthv_send_notification( $add_dienst_id, $add_user_id );
					$output .= '<div style="background:#d4edda;color:#155724;padding:10px;margin-bottom:15px;">Teilnehmer hinzugefügt.</div>';
				}
			}
		}
	}

	// Verarbeitung der Anmeldung (Teilnehmer eintragen)
	if ( isset( $_POST['thwthv_signup_dienst'] ) && is_user_logged_in() ) {
		if ( isset( $_POST['thwthv_signup_nonce'] ) && wp_verify_nonce( $_POST['thwthv_signup_nonce'], 'thwthv_frontend_signup' ) ) {
			$dienst_id = intval( $_POST['thwthv_signup_dienst'] );
			$user_id   = get_current_user_id();

			$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table_teilnehmer WHERE dienst_id = %d AND user_id = %d", $dienst_id, $user_id ) );

			if ( ! $exists ) {
				$wpdb->insert( $table_teilnehmer, array( 'dienst_id' => $dienst_id, 'user_id' => $user_id, 'rolle' => 'N' ) );
				thwthv_send_notification( $dienst_id, $user_id );
				$output .= '<div style="background:#d4edda;color:#155724;padding:10px;margin-bottom:15px;">Erfolgreich zum Dienst angemeldet.</div>';
			}
		}
	}

	// Formularverarbeitung (nur für berechtigte Nutzer)
	if ( isset( $_POST['thwthv_submit_dienst'] ) && thwthv_isAdmin() ) {
		if ( isset( $_POST['thwthv_nonce'] ) && wp_verify_nonce( $_POST['thwthv_nonce'], 'thwthv_frontend_save' ) ) {
			$wpdb->insert( $table_dienste, array(
				'datum'   => sanitize_text_field( $_POST['thwthv_datum'] ),
				'uhrzeit' => sanitize_text_field( $_POST['thwthv_uhrzeit'] ),
				'ort'     => sanitize_text_field( $_POST['thwthv_ort'] ),
			) );
			$output .= '<div style="background:#d4edda;color:#155724;padding:10px;margin-bottom:15px;">Dienst erfolgreich eingetragen.</div>';
		}
	}

	// Formularanzeige
	if ( thwthv_isAdmin() ) {
		$output .= '<div id="thv-new-dienst" style="margin-bottom:20px;padding:15px;border:1px solid #ddd;background:#f9f9f9;">';
		$output .= '<h3>Neuen Dienst eintragen</h3>';
		$output .= '<form method="post" action="#thv-new-dienst">';
		$output .= wp_nonce_field( 'thwthv_frontend_save', 'thwthv_nonce', true, false );
		$output .= '<p><label>Datum: <input type="date" name="thwthv_datum" required></label></p>';
		$output .= '<p><label>Uhrzeit: <input type="time" name="thwthv_uhrzeit" required></label></p>';
		$output .= '<p><label>Ort: <input type="text" name="thwthv_ort" required></label></p>';
		$output .= '<p><input type="submit" name="thwthv_submit_dienst" value="Eintragen" class="button"></p>';
		$output .= '</form></div>';

		$output .= '<div style="margin-bottom: 20px;">';
		$output .= '<a href="' . esc_url( admin_url( 'admin-post.php?action=thwthv_print_view' ) ) . '" target="_blank" class="button" style="margin-right: 10px;">PDF / Druckansicht (Alle)</a>';
		$output .= '<a href="' . esc_url( admin_url( 'admin-post.php?action=thwthv_print_view&mode=report' ) ) . '" target="_blank" class="button">Jahresbericht (Aktuell & Vorjahr)</a>';
		$output .= '</div>';
	}

	if ( thwthv_isAdmin() ) {
		// Admins: Aktuelles Jahr (ab 01.01.) + Zukunft
		$min_datum = date( 'Y-01-01', current_time( 'timestamp' ) );
	} else {
		// Andere: Nur Zukunft (ab heute)
		$min_datum = current_time( 'Y-m-d' );
	}
	$dienste = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table_dienste WHERE datum >= %s ORDER BY datum ASC", $min_datum ) );
	$output .= '<div class="thv-dienste-liste">';

	$all_users = array();
	if ( thwthv_isAdmin() ) {
		$all_users = get_users( array( 'orderby' => 'display_name' ) );
	}

	if ( $dienste ) {
		foreach ( $dienste as $dienst ) {
			$datum_fmt = date( 'd.m.Y', strtotime( $dienst->datum ) );

			$output .= '<div id="thv-dienst-' . $dienst->id . '" style="border:1px solid #ccc; margin-bottom:20px; background:#fff;">';
			$output .= '<div style="background:#f0f0f1; padding:10px; border-bottom:1px solid #ccc; font-weight:bold; display:flex; justify-content:space-between; align-items:center;">';
			$output .= '<span>' . sprintf( 'Datum: %s &nbsp;&nbsp; Uhrzeit: %s &nbsp;&nbsp; Ort: %s', esc_html( $datum_fmt ), esc_html( $dienst->uhrzeit ), esc_html( $dienst->ort ) ) . '</span>';
			
			if ( thwthv_isAdmin() ) {
				$output .= '<form method="post" action="#thv-dienst-' . $dienst->id . '" style="display:inline;">';
				$output .= wp_nonce_field( 'thwthv_delete_dienst', 'thwthv_delete_nonce', true, false );
				$output .= '<input type="hidden" name="thwthv_delete_dienst_id" value="' . $dienst->id . '">';
				$output .= '<button type="submit" name="thwthv_delete_dienst" value="1" class="button button-small" style="background:red; color:white; border-color:red;" onclick="return confirm(\'Dienst wirklich löschen? Alle Teilnehmerdaten gehen verloren.\')">Dienst löschen</button>';
				$output .= '</form>';
			}
			$output .= '</div>';
			$output .= '<div style="padding:10px;">';

			// Teilnehmer abrufen und formatieren
			$teilnehmer_rows = $wpdb->get_results( $wpdb->prepare( "SELECT user_id, rolle FROM $table_teilnehmer WHERE dienst_id = %d ORDER BY CASE rolle WHEN 'GF' THEN 1 WHEN 'KF' THEN 2 WHEN 'H' THEN 3 ELSE 4 END", $dienst->id ) );
			$teilnehmer_ids = array(); // Array für Check, ob User schon dabei ist
			$assigned_rows = array();
			$waiting_rows = array();

			foreach ( $teilnehmer_rows as $row ) {
				$teilnehmer_ids[] = $row->user_id;
				if ( 'N' === $row->rolle ) {
					$waiting_rows[] = $row;
				} else {
					$assigned_rows[] = $row;
				}
			}

			$groups = array(
				'Teilnehmer' => $assigned_rows,
				'Warteliste' => $waiting_rows,
			);

			foreach ( $groups as $group_label => $rows ) {
				$output .= '<div style="margin-top:10px;"><strong>' . esc_html( $group_label ) . ':</strong></div>';
				$output .= '<div style="margin-top:5px;">';

				if ( empty( $rows ) ) {
					$output .= '<div style="font-style:italic; color:#777; padding:5px 0;">-</div>';
				} else {
					foreach ( $rows as $row ) {
						$uid = $row->user_id;
						$rolle = $row->rolle ? $row->rolle : 'N';

						$role_labels = array(
							'N'  => 'nicht besetzt',
							'H'  => 'Helfer',
							'KF' => 'Kraftfahrer',
							'GF' => 'GrFü',
						);

						$user_info = get_userdata( $uid );
						if ( $user_info ) {
							$style = 'display:flex; justify-content:space-between; align-items:center; padding:5px 0;';
							$output .= '<div style="' . esc_attr( $style ) . '">';
							$output .= '<div style="display:flex; align-items:center;">';
							if ( thwthv_isAdmin() ) {
								$output .= '<form method="post" action="#thv-dienst-' . $dienst->id . '" style="display:inline; margin-right:10px;">';
								$output .= wp_nonce_field( 'thwthv_remove_participant', 'thwthv_remove_nonce', true, false );
								$output .= '<input type="hidden" name="thwthv_remove_participant_dienst" value="' . $dienst->id . '">';
								$output .= '<input type="hidden" name="thwthv_remove_participant_user" value="' . $uid . '">';
								$output .= '<button type="submit" name="thwthv_remove_participant" value="1" class="button button-small" style="background:red; color:white; border-color:red;" onclick="return confirm(\'Teilnehmer entfernen?\')">Löschen</button>';
								$output .= '</form>';
							}

							$output .= '<span>' . esc_html( $user_info->first_name . ' ' . $user_info->last_name );

							if ( thwthv_isAdmin() ) {
								// Statistik: Anzahl Dienste vor dem aktuellen Dienst
								$count_active = $wpdb->get_var( $wpdb->prepare(
									"SELECT COUNT(*) FROM $table_teilnehmer t 
									INNER JOIN $table_dienste d ON t.dienst_id = d.id 
									WHERE t.user_id = %d AND (d.datum < %s OR (d.datum = %s AND d.uhrzeit < %s)) AND t.rolle IN ('GF', 'KF', 'H')",
									$uid, $dienst->datum, $dienst->datum, $dienst->uhrzeit
								) );
								$count_n = $wpdb->get_var( $wpdb->prepare(
									"SELECT COUNT(*) FROM $table_teilnehmer t 
									INNER JOIN $table_dienste d ON t.dienst_id = d.id 
									WHERE t.user_id = %d AND (d.datum < %s OR (d.datum = %s AND d.uhrzeit < %s)) AND t.rolle = 'N'",
									$uid, $dienst->datum, $dienst->datum, $dienst->uhrzeit
								) );
								$output .= ' <small style="color:#777;">(G/K/H: ' . intval( $count_active ) . ', N: ' . intval( $count_n ) . ')</small>';
							}

							$output .= '</span>';
							$output .= '</div>';
							
							$output .= '<div>';
							if ( thwthv_isAdmin() ) {
								$output .= '<form method="post" action="#thv-dienst-' . $dienst->id . '" style="display:inline;">';
								$output .= wp_nonce_field( 'thwthv_update_role', 'thwthv_role_nonce', true, false );
								$output .= '<input type="hidden" name="thwthv_role_dienst" value="' . $dienst->id . '">';
								$output .= '<input type="hidden" name="thwthv_role_user" value="' . $uid . '">';
								$output .= '<input type="hidden" name="thwthv_update_role" value="1">';
								$output .= '<select name="thwthv_role_value" style="font-size:0.8em;padding:0;height:auto;margin-right:5px;" onchange="this.form.submit()">';
								foreach ( $role_labels as $val => $label ) {
									$output .= '<option value="' . esc_attr( $val ) . '" ' . selected( $rolle, $val, false ) . '>' . esc_html( $label ) . '</option>';
								}
								$output .= '</select>';
								$output .= '</form>';
							} else {
								$output .= '<span>(' . esc_html( isset( $role_labels[ $rolle ] ) ? $role_labels[ $rolle ] : $rolle ) . ')</span>';
							}
							$output .= '</div>'; // End right side
							$output .= '</div>'; // End row
						}
					}
				}
				$output .= '</div>'; // End group div
			}

			if ( thwthv_isAdmin() ) {
				$output .= '<div style="margin-top:10px;border-top:1px solid #eee;padding-top:5px;">';
				$output .= '<form method="post" action="#thv-dienst-' . $dienst->id . '">';
				$output .= wp_nonce_field( 'thwthv_add_participant', 'thwthv_add_nonce', true, false );
				$output .= '<input type="hidden" name="thwthv_add_participant_dienst" value="' . $dienst->id . '">';
				$output .= '<select name="thwthv_add_participant_user" style="max-width:150px;font-size:0.8em;">';
				$output .= '<option value="">+ User hinzufügen</option>';
				foreach ( $all_users as $user ) {
					if ( ! in_array( $user->ID, $teilnehmer_ids ) ) {
						$display = trim( $user->first_name . ' ' . $user->last_name );
						if ( empty( $display ) ) { $display = $user->user_login; }
						$output .= '<option value="' . $user->ID . '">' . esc_html( $display ) . '</option>';
					}
				}
				$output .= '</select> <input type="submit" name="thwthv_add_participant" value="OK" class="button button-small" style="font-size:0.8em;padding:0 5px;height:auto;line-height:2;">';
				$output .= '</form></div>';
			}

			// Button für Anmeldung generieren
			$output .= '<div style="margin-top:15px; text-align:right;">';
			if ( is_user_logged_in() ) {
				if ( in_array( get_current_user_id(), $teilnehmer_ids ) ) {
					$output .= '<em>Bereits eingetragen</em>';
				} else {
					$output .= '<form method="post" action="#thv-dienst-' . $dienst->id . '" style="display:inline;">';
					$output .= wp_nonce_field( 'thwthv_frontend_signup', 'thwthv_signup_nonce', true, false );
					$output .= '<input type="hidden" name="thwthv_signup_dienst" value="' . $dienst->id . '">';
					$output .= '<input type="submit" value="In Warteliste eintragen" class="button" onclick="return confirm(\'Die Eintragung ist verbindlich. Soll die Eintragung vorgenommen werden?\')">';
					$output .= '</form>';
				}
			} else {
				$output .= 'Login erforderlich';
			}
			$output .= '</div>';

			$output .= '</div>'; // End padding div
			$output .= '</div>'; // End main card div
		}
	} else {
		$output .= '<p>Keine Dienste vorhanden.</p>';
	}

	$output .= '</div>';
	return $output;
}
add_shortcode( 'thv_dienste', 'thwthv_shortcode_dienste' );

/**
 * Generiert eine Druckansicht für die Dienste (PDF Export via Browser).
 */
function thwthv_handle_print_view() {
	if ( ! thwthv_isAdmin() ) {
		wp_die( 'Zugriff verweigert.' );
	}

	global $wpdb;
	$table_dienste = $wpdb->prefix . 'thv_dienste';
	$table_teilnehmer = $wpdb->prefix . 'thv_teilnehmer';

	$mode = isset( $_GET['mode'] ) ? $_GET['mode'] : '';
	$title = 'THV Dienstplan Übersicht';

	if ( 'report' === $mode ) {
		$year_current = current_time( 'Y' );
		$year_last    = $year_current - 1;
		$start        = $year_last . '-01-01';
		$end          = $year_current . '-12-31';
		$dienste      = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table_dienste WHERE datum >= %s AND datum <= %s ORDER BY datum ASC", $start, $end ) );
		$title        = "Jahresbericht $year_last & $year_current";
	} else {
		$dienste = $wpdb->get_results( "SELECT * FROM $table_dienste ORDER BY datum ASC" );
	}

	$role_labels = array(
		'N'  => 'Warteliste',
		'H'  => 'Helfer',
		'KF' => 'Kraftfahrer',
		'GF' => 'GrFü',
	);
	?>
	<!DOCTYPE html>
	<html lang="de">
	<head>
		<meta charset="UTF-8">
		<title><?php echo esc_html( $title ); ?></title>
		<style>
			body { font-family: sans-serif; font-size: 14px; color: #333; max-width: 210mm; margin: 0 auto; }
			h1 { text-align: center; margin-bottom: 30px; }
			.dienst-block { border: 1px solid #ccc; margin-bottom: 20px; page-break-inside: avoid; }
			.dienst-header { background-color: #f0f0f0; padding: 10px; font-weight: bold; border-bottom: 1px solid #ccc; }
			.dienst-content { padding: 10px; }
			table { width: 100%; border-collapse: collapse; }
			th, td { text-align: left; padding: 5px; vertical-align: top; }
			.role-label { font-weight: bold; display: inline-block; width: 100px; }
			@media print {
				.no-print { display: none; }
				body { margin: 0; max-width: 100%; }
				.dienst-block { border: 1px solid #000; }
			}
		</style>
	</head>
	<body>
		<div class="no-print" style="padding: 20px; text-align: right; background: #eee; border-bottom: 1px solid #ccc; margin-bottom: 20px;">
			<button onclick="window.print();" style="font-size: 16px; padding: 10px 20px; cursor: pointer;">🖨️ Seite drucken / Als PDF speichern</button>
		</div>
		<h1><?php echo esc_html( $title ); ?></h1>
		<?php if ( $dienste ) : foreach ( $dienste as $dienst ) : 
			$teilnehmer_rows = $wpdb->get_results( $wpdb->prepare( "SELECT user_id, rolle FROM $table_teilnehmer WHERE dienst_id = %d ORDER BY CASE rolle WHEN 'GF' THEN 1 WHEN 'KF' THEN 2 WHEN 'H' THEN 3 ELSE 4 END", $dienst->id ) );
			?>
			<div class="dienst-block">
				<div class="dienst-header"><?php echo esc_html( date( 'd.m.Y', strtotime( $dienst->datum ) ) ); ?> | <?php echo esc_html( $dienst->uhrzeit ); ?> Uhr | <?php echo esc_html( $dienst->ort ); ?></div>
				<div class="dienst-content">
					<table><tr>
						<td width="<?php echo ( 'report' === $mode ) ? '100%' : '60%'; ?>"><strong>Eingeteilt (GF, KF, H):</strong><br>
							<?php 
							$has = false; 
							foreach ( $teilnehmer_rows as $r ) { 
								if ( 'N' !== $r->rolle ) { 
									$has = true; 
									$u = get_userdata( $r->user_id ); 
									echo '<div><span class="role-label">' . esc_html( isset( $role_labels[ $r->rolle ] ) ? $role_labels[ $r->rolle ] : $r->rolle ) . ':</span> ' . esc_html( $u ? $u->display_name : 'Unbekannt' ) . '</div>'; 
								} 
							} 
							if ( ! $has ) echo '-'; 
							?>
						</td>
						<?php if ( 'report' !== $mode ) : ?>
						<td width="40%" style="border-left:1px solid #eee;padding-left:10px;"><strong>Warteliste:</strong><br>
							<?php 
							$has = false; 
							foreach ( $teilnehmer_rows as $r ) { 
								if ( 'N' === $r->rolle ) { 
									$has = true; 
									$u = get_userdata( $r->user_id ); 
									echo '<div>' . esc_html( $u ? $u->display_name : 'Unbekannt' ) . '</div>'; 
								} 
							} 
							if ( ! $has ) echo '-'; 
							?>
						</td>
						<?php endif; ?>
					</tr></table>
				</div>
			</div>
		<?php endforeach; else : ?><p>Keine Dienste vorhanden.</p><?php endif; ?>
	</body>
	</html>
	<?php
}
add_action( 'admin_post_thwthv_print_view', 'thwthv_handle_print_view' );