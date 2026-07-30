<div class="card"> <!-- Document Type & Workflow Management -->
    <h3 class="card-title">Document Type & Workflow Management</h3>
    <div class="card-body">
        <!-- Add New Document Type Form -->
        <div class="doc-type-item" style="background: #f0f9ff; border-color: #bae6fd;">
            <h4 style="margin-top:0; color: #0c4a6e;">Add New Template</h4>
            <form method="POST">
                <div style="display: grid; grid-template-columns: 2fr 1fr 1fr; gap: 15px;">
                    <div class="input-group">
                        <label>Document Name</label>
                        <input type="text" name="doc_type_name" required>
                    </div>
                    <div class="input-group">
                        <label>ARTA Level</label>
                        <select name="doc_type_arta" required>
                            <?php foreach($all_arta_levels as $level): ?><option value="<?php echo htmlspecialchars($level['level_name']); ?>"><?php echo htmlspecialchars($level['level_name']); ?></option><?php endforeach; ?>
                        </select> 
                    </div>
                    <div class="input-group">
                        <label>Workflow Type</label>
                        <select name="doc_workflow_type">
                            <option value="Approval">Approval</option>
                            <option value="Transfer">Transfer</option>
                        </select>
                    </div>
                <div class="input-group" style="grid-column: span 3;">
                    <label>Final Status Text (e.g., "Leave Approved", "Ready for Release")</label>
                    <input type="text" name="doc_final_status" placeholder="Leave empty for default 'Ready for Release'">
                </div>
                </div>
                <div class="input-group">
                    <label>Default Routing Sequence</label>
                    <div class="workflow-builder" data-id="new">
                        <div style="display: flex; gap: 10px; margin-bottom: 10px;">
                            <select class="officeSelect" style="flex: 1;">
                                <option value="Department Head">Department Head (of Requestor)</option>
                                <?php foreach($department_names as $dept_name): ?><option value="<?php echo htmlspecialchars($dept_name); ?>"><?php echo htmlspecialchars($dept_name); ?></option>
                                <option value="<?php echo htmlspecialchars($dept_name . ' (Head)'); ?>"><?php echo htmlspecialchars($dept_name . ' (Head)'); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="button" class="btn btn-small btn-add-step">+ Add</button>
                        </div>
                        <ul class="routeList"></ul>
                        <input type="hidden" name="doc_type_workflow" class="workflowInput" value="[]">
                    </div>
                </div>
                <button type="submit" name="add_doc_type" class="btn btn-small">Save New Template</button>
            </form>
        </div>

        <!-- List Existing Document Types -->
        <form method="POST" id="bulkDeleteForm" onsubmit="return confirm('Are you sure you want to delete the selected document types?');">
            <input type="hidden" name="delete_bulk_doc_types" value="1">
        </form>
        <h4 style="margin-top: 30px; display: flex; align-items: center; gap: 10px;">
                <input type="checkbox" id="selectAllDocTypes" style="width: 20px; height: 20px;">
                <label for="selectAllDocTypes" style="margin-bottom: 0; font-size: 1.1rem; color: var(--text-dark); cursor: pointer;">Select All</label>
                Existing Templates
            </h4>
            <?php foreach($all_doc_types as $type):
                $workflow = json_decode($type['default_workflow'] ?? '[]', true);
            ?>
                <div class="doc-type-item" id="item-<?php echo $type['id']; ?>">
                    <div class="display-view">
                        <div style="display: flex; align-items: center; gap: 15px;">
                            <input type="checkbox" name="doc_type_ids[]" value="<?php echo $type['id']; ?>" form="bulkDeleteForm" style="width: 20px; height: 20px;">
                            <div class="doc-type-info">
                                <strong><?php echo htmlspecialchars($type['name']); ?></strong>
                                <small>ARTA: <?php echo $type['arta_level']; ?> | Type: <?php echo $type['workflow_type']; ?></small>
                                <ul class="workflow-list">
                                    <?php foreach($workflow as $step): ?><li><?php echo htmlspecialchars($step); ?></li><?php endforeach; ?>
                                </ul>
                            </div>
                        </div>
                        <div class="user-actions">
                            <button type="button" class="btn btn-small btn-edit" onclick="toggleEditView(<?php echo $type['id']; ?>)">Edit</button>
                        </div>
                    </div>
                    <div class="edit-view" style="position: relative;">
                        <!-- Form for UPDATE -->
                        <form method="POST" style="margin-top: 15px;">
                            <input type="hidden" name="doc_type_id" value="<?php echo $type['id']; ?>">
                            <div style="display: grid; grid-template-columns: 2fr 1fr 1fr; gap: 15px;">
                                <input type="text" name="doc_type_name" value="<?php echo htmlspecialchars($type['name']); ?>" required>
                                            <select name="doc_type_arta" required>
                                                <?php foreach($all_arta_levels as $level): ?><option value="<?php echo htmlspecialchars($level['level_name']); ?>" <?php if($type['arta_level'] == $level['level_name']) echo 'selected'; ?>><?php echo htmlspecialchars($level['level_name']); ?></option><?php endforeach; ?>
                                            </select> 
                                <select name="doc_workflow_type">
                                    <option value="Approval" <?php if($type['workflow_type'] == 'Approval') echo 'selected'; ?>>Approval</option>
                                    <option value="Transfer" <?php if($type['workflow_type'] == 'Transfer') echo 'selected'; ?>>Transfer</option>
                                </select>
                            </div>
                            <div class="input-group" style="margin-top: 15px;">
                                <label>Default Routing Sequence</label>
                                <div class="workflow-builder" data-id="<?php echo $type['id']; ?>">
                                    <div style="display: flex; gap: 10px; margin-bottom: 10px;">
                                        <select class="officeSelect" style="flex: 1;"><option value="Department Head">Department Head (of Requestor)</option>
                                        <?php foreach($department_names as $dept_name): ?><option value="<?php echo htmlspecialchars($dept_name); ?>"><?php echo htmlspecialchars($dept_name); ?></option>
                                        <option value="<?php echo htmlspecialchars($dept_name . ' (Head)'); ?>"><?php echo htmlspecialchars($dept_name . ' (Head)'); ?></option>
                                        <?php endforeach; ?>
                                        </select> 
                                        <button type="button" class="btn btn-small btn-add-step">+ Add</button>
                                    </div>
                                    <ul class="routeList"></ul>
                                    <input type="hidden" name="doc_type_workflow" class="workflowInput" value="<?php echo htmlspecialchars($type['default_workflow'] ?? '[]', ENT_QUOTES); ?>">
                                </div>
                            </div>
                            <div class="edit-actions" style="display:flex; gap:10px; align-items:center;">
                                <button type="submit" name="update_doc_type" class="btn btn-small">Save Changes</button>
                                <button type="button" class="btn btn-small btn-cancel" onclick="toggleEditView(<?php echo $type['id']; ?>)">Cancel</button>
                            </div>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
    </div>
    <div class="card-footer">
            <div class="bulk-actions">
                <button type="submit" form="bulkDeleteForm" class="btn btn-small btn-delete">Delete Selected</button>
            </div>
    </div>
</div>