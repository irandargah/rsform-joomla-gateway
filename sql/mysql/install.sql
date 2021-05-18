INSERT IGNORE INTO `#__rsform_config` (`SettingName`, `SettingValue`) VALUES
('irandargahgateway.merchant_id', ''),
('irandargahgateway.currency', '');

INSERT IGNORE INTO `#__rsform_component_types` (`ComponentTypeId`, `ComponentTypeName`) VALUES (1020, 'irandargahgateway');

DELETE FROM #__rsform_component_type_fields WHERE ComponentTypeId = 1020;
INSERT IGNORE INTO `#__rsform_component_type_fields` (`ComponentTypeId`, `FieldName`, `FieldType`, `FieldValues`, `Ordering`) VALUES
(1020, 'NAME', 'textbox', '', 0),
(1020, 'LABEL', 'textbox', '', 1),
(1020, 'COMPONENTTYPE', 'hidden', '1020', 2),
(1020, 'LAYOUTHIDDEN', 'hiddenparam', 'YES', 7);
