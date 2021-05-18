DELETE FROM #__rsform_config WHERE SettingName = 'irandargahgateway.merchant_id';
DELETE FROM #__rsform_config WHERE SettingName = 'irandargahgateway.currency';

DELETE FROM #__rsform_component_types WHERE ComponentTypeId = 1020;
DELETE FROM #__rsform_component_type_fields WHERE ComponentTypeId = 1020;
