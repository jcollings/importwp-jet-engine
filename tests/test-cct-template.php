<?php

use ImportWPAddon\JetEngine\Importer\Template\CustomContentTypeTemplate;

class Test_CustomContentTypeTemplate extends WP_UnitTestCase
{
	/**
	 * @dataProvider provide_checkbox_field_data
	 */
	public function test_process_fields_checkbox_field($expected, $settings = [], $options = [], $input = [])
	{
		$mock_cct = $this->createPartialMock(CustomContentTypeTemplate::class, []);

		$field_prefix = 'content-type.checkbox_1';

		$fields = [
			$field_prefix => implode(',', $input)
		];

		$cct_fields = [
			array_merge([
				'name' => 'checkbox_1',
				'type' => 'checkbox',
				'options' => $options,
			], $settings)
		];

		$this->assertEquals([
			'checkbox_1' => $expected
		], $mock_cct->process_fields($fields, $cct_fields));
	}

	public function provide_checkbox_field_data()
	{
		// list of values
		$input = ['test1', 'test2'];

		// ['key' => '', 'value' => '' ]
		$options = [
			['key' => 'test1', 'value' => 'test1'],
			['key' => 'test2', 'value' => 'test2'],
			['key' => 'test3', 'value' => 'test3'],
		];

		return [
			'Checkbox' => [
				[
					'test1' => 'true',
					'test2' => 'true',
					'test3' => 'false',
				],
				[
					'allow_custom' => false,
					'is_array' => false,
				],
				$options,
				$input,
			],
			'Checkbox is_array' => [
				[
					'test1',
					'test2',
				],
				[
					'allow_custom' => false,
					'is_array' => true,
				],
				$options,
				$input,
			],
			'Checkbox custom' => [
				[
					'test1' => 'true',
					'test2' => 'true',
					'test3' => 'false',
					'testCustom' => 'true'
				],
				[
					'allow_custom' => true,
					'is_array' => false,
				],
				$options,
				array_merge($input, ['testCustom']),
			],
			'Checkbox is_array and custom' => [
				[
					'test1',
					'test2',
					'testCustom',
				],
				[
					'allow_custom' => true,
					'is_array' => true,
				],
				$options,
				array_merge($input, ['testCustom']),
			],
		];
	}
}
