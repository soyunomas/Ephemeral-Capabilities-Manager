/**
 * Ephemeral Capabilities Manager Admin Script v1.0.0
 *
 * Handles dynamic population of the task dropdown based on selected user's capabilities,
 * reading data from a localized JS object (ecm_admin_params).
 */
(function($) {
    'use strict';

    $(document).ready(function() {
        const userSelect = $('#ecm_user_id');
        const taskSelect = $('#ecm_task');
        const taskDescriptionSpan = $('#ecm_task_description');

        // *** CAMBIO: Acceder a los datos desde el objeto global ***
        // Verificar que el objeto global existe
        if (typeof ecm_admin_params === 'undefined' || typeof ecm_admin_params.bundles === 'undefined') {
            console.error('ECM Admin: Localized data (ecm_admin_params) not found or invalid.');
            taskSelect.prop('disabled', true).empty().append($('<option></option>').val("").text('Error: Missing Config Data'));
            return;
        }

        // --- Data Variables ---
        const allBundles = ecm_admin_params.bundles || {};
        const allUserCaps = ecm_admin_params.userCapabilities || {};
        const i18n = ecm_admin_params.i18n || {};

        // --- Helper Functions ---
        function createPlaceholderOption(text) {
            return $('<option></option>').val("").text(text);
        }
        function createTaskOption(taskKey, bundle) {
             let riskLabel = '';
             let riskStyle = {};
             switch (bundle.risk) {
                 case 'medium': riskLabel = ` (${i18n.riskMedium || 'Risk: Medium'})`; break;
                 case 'high':   riskLabel = ` (${i18n.riskHigh || 'Risk: High!'})`; riskStyle = { color: 'orange', fontWeight: 'bold'}; break;
                 case 'critical': riskLabel = ` (${i18n.riskCritical || 'RISK: CRITICAL!'})`; riskStyle = { color: 'red', fontWeight: 'bold'}; break;
             }
             return $('<option></option>')
                 .val(taskKey)
                 .text(bundle.label + riskLabel)
                 .css(riskStyle);
         }

        // Function to update the task dropdown
        function updateTaskDropdown() {
            const selectedUserId = userSelect.val();
            const currentUserCaps = selectedUserId && allUserCaps[selectedUserId] ? allUserCaps[selectedUserId] : {};
            const previousSelectedTask = taskSelect.val(); // Store before clearing

            taskSelect.empty();
            updateTaskDescription();

            if (!selectedUserId) {
                taskSelect.append(createPlaceholderOption(i18n.selectUserFirst || '-- Select User First --'));
                taskSelect.prop('disabled', true);
                return;
            }

            taskSelect.prop('disabled', false);
            taskSelect.append(createPlaceholderOption(i18n.selectTask || '-- Select Task --'));

            let addedOptions = [];
            $.each(allBundles, function(taskKey, bundle) {
                let grantsNewCapability = false;
                if (bundle.capabilities && Array.isArray(bundle.capabilities)) {
                    for (const cap of bundle.capabilities) {
                        // Check if user lacks cap or it's explicitly false
                        if (!currentUserCaps.hasOwnProperty(cap) || !currentUserCaps[cap]) {
                            grantsNewCapability = true;
                            break;
                        }
                    }
                }

                if (grantsNewCapability) {
                    taskSelect.append(createTaskOption(taskKey, bundle));
                    addedOptions.push(taskKey);
                }
            });

             if (previousSelectedTask && addedOptions.includes(previousSelectedTask)) {
                taskSelect.val(previousSelectedTask);
            }

             if (taskSelect.val()) {
                taskSelect.trigger('change');
             } else {
                 updateTaskDescription();
             }
        }

        // Function to update the task description
        function updateTaskDescription() {
             const selectedTaskKey = taskSelect.val();
             if (taskDescriptionSpan.length) {
                 if (selectedTaskKey && allBundles[selectedTaskKey] && allBundles[selectedTaskKey].description) {
                     taskDescriptionSpan.text(allBundles[selectedTaskKey].description);
                 } else {
                     taskDescriptionSpan.text('');
                 }
             }
        }

        // --- Event Listeners ---
        userSelect.on('change', updateTaskDropdown);
        taskSelect.on('change', updateTaskDescription);

        // --- Initial State ---
        if (!userSelect.val()) {
             taskSelect.prop('disabled', true);
             taskSelect.empty().append(createPlaceholderOption(i18n.selectUserFirst || '-- Select User First --'));
        } else {
            updateTaskDropdown();
        }

    }); // End document.ready

})(jQuery);
