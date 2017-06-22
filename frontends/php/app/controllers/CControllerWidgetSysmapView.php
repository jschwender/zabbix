<?php
/*
** Zabbix
** Copyright (C) 2001-2017 Zabbix SIA
**
** This program is free software; you can redistribute it and/or modify
** it under the terms of the GNU General Public License as published by
** the Free Software Foundation; either version 2 of the License, or
** (at your option) any later version.
**
** This program is distributed in the hope that it will be useful,
** but WITHOUT ANY WARRANTY; without even the implied warranty of
** MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
** GNU General Public License for more details.
**
** You should have received a copy of the GNU General Public License
** along with this program; if not, write to the Free Software
** Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
**/

require_once dirname(__FILE__).'/../../include/blocks.inc.php';

class CControllerWidgetSysmapView extends CController {
	protected function init() {
		$this->disableSIDValidation();
	}

	protected function checkInput() {
		$fields = [
			'name'			=>	'string',
			'uniqueid'		=>	'required|string',
			'fullscreen'	=>	'in 0,1',
			'fields'		=>	'array'
		];

		$ret = $this->validateInput($fields);

		if ($ret) {
			$input_fields = getRequest('fields');

			$validationRules = [
				'source_type' => 'fatal|required|in '.WIDGET_SYSMAP_SOURCETYPE_MAP.','.WIDGET_SYSMAP_SOURCETYPE_FILTER,
				'previous_maps' =>	'string'
			];

			if (array_key_exists('source_type', $input_fields)) {
				if ($input_fields['source_type'] == WIDGET_SYSMAP_SOURCETYPE_FILTER) {
					$validationRules['filter_widget_reference'] = 'string';
					$validationRules['sysmapid'] = 'db sysmaps.sysmapid';
				}
				else {
					$validationRules['sysmapid'] = 'required|db sysmaps.sysmapid';
				}

				$validator = new CNewValidator($input_fields, $validationRules);

				$errors = $validator->getAllErrors();
				if ($errors) {
					$ret = false;

					foreach ($validator->getAllErrors() as $error) {
						info($error);
					}
				}
			}
		}

		if (!$ret) {
			$output = [];

			if (($messages = getMessages()) !== null) {
				$output['errors'][] = $messages->toString();
			}

			$this->setResponse(new CControllerResponseData(['main_block' => CJs::encodeJson($output)]));
		}

		return $ret;
	}

	protected function checkPermissions() {
		return ($this->getUserType() >= USER_TYPE_ZABBIX_USER);
	}

	protected function doAction() {
		$uniqueid = $this->getInput('uniqueid');
		$data = $this->getInput('fields');

		$this->filter_widget_reference = array_key_exists('filter_widget_reference', $data)
			? $data['filter_widget_reference']
			: null;

		// get previous map
		$previous_map = null;
		if (array_key_exists('previous_maps', $data)) {
			$previous_map = array_filter(explode(',', $data['previous_maps']), 'is_numeric');

			if ($previous_map) {
				$previous_map = API::Map()->get([
					'sysmapids' => [array_pop($previous_map)],
					'output' => ['sysmapid', 'name']
				]);

				$previous_map = reset($previous_map);
			}
		}

		// Get requested map:
		$options = [
			'severity_min' => 0,
			'fullscreen' => getRequest('fullscreen', 0)
		];

		$sysmapid = array_key_exists('sysmapid', $data) ? [$data['sysmapid']] : [];
		$sysmap_data = CMapHelper::get($sysmapid, $options);

		// Rewrite actions to force Submaps be opened in same widget, instead of separate window.
		foreach ($sysmap_data['elements'] as &$element) {
			$actions = json_decode($element['actions'], true);
			if ($actions && array_key_exists('gotos', $actions) && array_key_exists('submap', $actions['gotos'])) {
				$actions['navigatetos']['submap'] = $actions['gotos']['submap'];
				$actions['navigatetos']['submap']['widget_uniqueid'] = $uniqueid;
				unset($actions['gotos']['submap']);
			}

			$element['actions'] = json_encode($actions);
		}
		unset($element);

		// Pass variables to view.
		$this->setResponse(new CControllerResponseData([
			'name' => $this->getInput('name', CWidgetConfig::getKnownWidgetTypes()[WIDGET_SYSMAP]),
			'user' => [
				'debug_mode' => $this->getDebugMode()
			],
			'sysmap_data' => $sysmap_data,
			'previous_map' => $previous_map,
			'uniqueid' => $uniqueid,
			'fields' => $data
		]));
	}
}
