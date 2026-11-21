<?php
/**
 * Native component mount for an individual Customizer setting.
 *
 * @package MDS_Release_Manager
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MDS_RM_Customizer_Control extends WP_Customize_Control {
	public $type = 'mds-rm-component';
	public $component_type = '';
	public string $group_heading = '';

	/** Outputs the configuration consumed by the component. */
	public function render_content(): void {
		if ( '' !== $this->group_heading ) {
			echo '<h4 class="mds-rm-customizer-group-heading">' . esc_html( $this->group_heading ) . '</h4>';
		}
		$config = array(
			'id'      => $this->id,
			'type'    => $this->component_type,
			'label'   => $this->label,
			'attrs'   => $this->input_attrs,
			'choices' => $this->choices,
			'default' => $this->setting->default,
		);
		echo '<div class="mds-rm-customizer-control" data-config="' . esc_attr( wp_json_encode( $config ) ) . '"></div>';
	}
}
