<?php
/**
 * Plugin-specific settings (shared AI settings live in Core3\Core\Settings_Store).
 *
 * @package Dev3\AiEventsCalendar
 */

namespace Dev3\AiEventsCalendar;

use Dev3\AiEventsCalendar\Content\Social;
use Dev3\AiEventsCalendar\Core3\Core\Settings_Store;

defined( 'ABSPATH' ) || exit;

/**
 * Hooked into dev3ec_settings_defaults / dev3ec_settings_sanitize.
 */
class Settings {

	const TONES = array( 'friendly', 'professional', 'exciting', 'warm', 'concise', 'formal', 'playful' );

	/**
	 * Adds plugin defaults. No translated strings here: this runs before init.
	 *
	 * @param array $defaults Shared defaults.
	 * @return array
	 */
	public static function defaults( $defaults ) {
		return array_merge(
			$defaults,
			array(
				'default_tone'     => 'friendly',
				'default_language' => '',
				'networks'         => array( 'x', 'facebook', 'instagram', 'linkedin' ),
				'hashtags'         => '',
				'faq_display'      => 'append',
				'faq_schema'       => true,
				'seo_output'       => 'auto',
				'email_from_name'  => '',
				'email_reply_to'   => '',
				'auto_reminder'    => false,
				'reminder_hours'   => 24,
				'import_status'    => 'draft',
				'import_featured'  => true,
			)
		);
	}

	/**
	 * Sanitizes plugin keys.
	 *
	 * @param array $out     Sanitized so far.
	 * @param array $input   Raw input.
	 * @param array $current Current.
	 * @return array
	 */
	public static function sanitize( $out, $input, $current ) {
		unset( $current );
		if ( isset( $input['default_tone'] ) && in_array( $input['default_tone'], self::TONES, true ) ) {
			$out['default_tone'] = $input['default_tone'];
		}
		if ( isset( $input['default_language'] ) ) {
			$out['default_language'] = mb_substr( sanitize_text_field( wp_unslash( (string) $input['default_language'] ) ), 0, 40 );
		}
		if ( isset( $input['networks'] ) && is_array( $input['networks'] ) ) {
			$out['networks'] = array_values( array_intersect( array_keys( Social::NETWORKS ), array_map( 'strval', $input['networks'] ) ) );
		}
		if ( isset( $input['hashtags'] ) ) {
			$out['hashtags'] = mb_substr( sanitize_text_field( wp_unslash( (string) $input['hashtags'] ) ), 0, 200 );
		}
		if ( isset( $input['faq_display'] ) && in_array( $input['faq_display'], array( 'append', 'shortcode' ), true ) ) {
			$out['faq_display'] = $input['faq_display'];
		}
		if ( isset( $input['faq_schema'] ) ) {
			$out['faq_schema'] = (bool) $input['faq_schema'];
		}
		if ( isset( $input['seo_output'] ) && in_array( $input['seo_output'], array( 'auto', 'off' ), true ) ) {
			$out['seo_output'] = $input['seo_output'];
		}
		if ( isset( $input['email_from_name'] ) ) {
			$out['email_from_name'] = mb_substr( sanitize_text_field( wp_unslash( (string) $input['email_from_name'] ) ), 0, 80 );
		}
		if ( isset( $input['email_reply_to'] ) ) {
			$email                 = sanitize_email( (string) $input['email_reply_to'] );
			$out['email_reply_to'] = is_email( $email ) ? $email : '';
		}
		if ( isset( $input['auto_reminder'] ) ) {
			$out['auto_reminder'] = (bool) $input['auto_reminder'];
		}
		if ( isset( $input['reminder_hours'] ) ) {
			$out['reminder_hours'] = min( 168, max( 1, (int) $input['reminder_hours'] ) );
		}
		if ( isset( $input['import_status'] ) && in_array( $input['import_status'], array( 'draft', 'pending' ), true ) ) {
			$out['import_status'] = $input['import_status'];
		}
		if ( isset( $input['import_featured'] ) ) {
			$out['import_featured'] = (bool) $input['import_featured'];
		}
		return $out;
	}

	/**
	 * Reads a setting.
	 *
	 * @param string $key      Key.
	 * @param mixed  $fallback Fallback.
	 * @return mixed
	 */
	public static function get( $key, $fallback = null ) {
		return Settings_Store::get( $key, $fallback );
	}

	/**
	 * Output language for prompts.
	 *
	 * @param string $requested Requested language.
	 * @return string
	 */
	public static function language( $requested = '' ) {
		$lang = mb_substr( sanitize_text_field( (string) $requested ), 0, 40 );
		if ( '' === $lang ) {
			$lang = (string) self::get( 'default_language', '' );
		}
		return '' !== $lang ? $lang : get_locale();
	}

	/**
	 * Tone (validated).
	 *
	 * @param string $requested Requested.
	 * @return string
	 */
	public static function tone( $requested = '' ) {
		if ( in_array( $requested, self::TONES, true ) ) {
			return $requested;
		}
		$t = (string) self::get( 'default_tone', 'friendly' );
		return in_array( $t, self::TONES, true ) ? $t : 'friendly';
	}
}
