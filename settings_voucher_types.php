<div class="card"> <!-- Financial Voucher Types & Requirements -->
    <h3 class="card-title">Financial Voucher Types & Requirements</h3>
        <p style="font-size: 0.85rem; color: var(--text-muted); margin-top: -20px; margin-bottom: 25px;">Define types for financial transactions and their required attachments. These appear when a user creates a financial request.</p>
    <div class="card-body">

        <!-- Add New Voucher Type -->
        <div class="doc-type-item" style="background: #fffbeb; border-color: #fde68a;">
            <h4 style="margin-top:0; color: #92400e;">Add New Financial Type</h4>
            <form method="POST">
                <div class="input-group">
                    <label>Voucher Type Name</label>
                    <input type="text" name="voucher_type_name" placeholder="e.g., Travel Reimbursement, Cash Advance" required>
                </div>
                <div class="input-group">
                    <label>ARTA Level</label>
                    <select name="voucher_arta_level" required>
                        <?php foreach($all_arta_levels as $level): ?><option value="<?php echo htmlspecialchars($level['level_name']); ?>"><?php echo htmlspecialchars($level['level_name']); ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="input-group">
                    <label>Requirements Checklist</label>
                    <textarea name="requirements" placeholder="Enter one requirement per line..."></textarea>
                </div>
                <div class="input-group">
                    <label>Mandatory Routing Sequence</label>
                    <div class="workflow-builder" data-id="v-new">
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
                        <input type="hidden" name="voucher_type_workflow" class="workflowInput" value="[]">
                    </div>
                </div>
                <button type="submit" name="add_voucher_type" class="btn btn-small btn-gold">Save New Financial Type</button>
            </form>
        </div>

        <!-- List Existing Voucher Types -->
        <form method="POST" id="bulkDeleteVoucherTypeForm" onsubmit="return confirm('Are you sure you want to delete the selected financial voucher types?');">
            <input type="hidden" name="delete_bulk_voucher_types" value="1">
        </form>
        <h4 style="margin-top: 30px; display: flex; align-items: center; gap: 10px;">
            <input type="checkbox" id="selectAllVoucherTypes" style="width: 20px; height: 20px;">
            <label for="selectAllVoucherTypes" style="margin-bottom: 0; font-size: 1.1rem; color: var(--text-dark); cursor: pointer;">Select All</label>
            Existing Financial Types
        </h4>
        <?php foreach($all_voucher_types as $v_type): 
            $v_workflow = json_decode($v_type['default_workflow'] ?? '[]', true);
            $v_reqs = json_decode($v_type['requirements'] ?? '[]', true);
        ?>
            <div class="doc-type-item" id="item-v-<?php echo $v_type['id']; ?>">
                <div class="display-view">
                    <div style="display: flex; align-items: center; gap: 15px;">
                        <input type="checkbox" name="voucher_type_ids[]" value="<?php echo $v_type['id']; ?>" form="bulkDeleteVoucherTypeForm" style="width: 20px; height: 20px;">
                        <div class="doc-type-info">
                            <strong><?php echo htmlspecialchars($v_type['name']); ?></strong>
                            <small>ARTA: <?php echo htmlspecialchars($v_type['arta_level']); ?> | Requirements: <?php echo count($v_reqs); ?> items</small>
                            <ul class="workflow-list">
                                <?php foreach($v_workflow as $step): ?><li><?php echo htmlspecialchars($step); ?></li><?php endforeach; ?>
                            </ul>
                        </div>
                    </div>
                    <div class="user-actions">
                        <button type="button" class="btn btn-small btn-edit" onclick="toggleVoucherEditView(<?php echo $v_type['id']; ?>)">Edit</button>
                    </div>
                </div>
                <div class="edit-view" style="position: relative;">
                    <form method="POST">
                        <input type="hidden" name="voucher_type_id" value="<?php echo $v_type['id']; ?>">
                        <div class="input-group">
                            <label>Voucher Type Name</label>
                            <input type="text" name="voucher_type_name" value="<?php echo htmlspecialchars($v_type['name']); ?>" required>
                        </div>
                        <div class="input-group">
                            <label>ARTA Level</label>
                            <select name="voucher_arta_level" required>
                                <?php foreach($all_arta_levels as $level): ?><option value="<?php echo htmlspecialchars($level['level_name']); ?>" <?php if($v_type['arta_level'] == $level['level_name']) echo 'selected'; ?>><?php echo htmlspecialchars($level['level_name']); ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="input-group">
                            <label>Requirements (one per line)</label>
                            <textarea name="requirements"><?php echo htmlspecialchars(implode("\n", $v_reqs)); ?></textarea>
                        </div>
                        <div class="input-group">
                            <label>Mandatory Routing Sequence</label>
                            <div class="workflow-builder" data-id="v-<?php echo $v_type['id']; ?>">
                                <div style="display: flex; gap: 10px; margin-bottom: 10px;">
                                    <select class="officeSelect" style="flex: 1;"><option value="Department Head">Department Head (of Requestor)</option>
                                    <?php foreach($department_names as $dept_name): ?><option value="<?php echo htmlspecialchars($dept_name); ?>"><?php echo htmlspecialchars($dept_name); ?></option>
                                    <option value="<?php echo htmlspecialchars($dept_name . ' (Head)'); ?>"><?php echo htmlspecialchars($dept_name . ' (Head)'); ?></option>
                                    <?php endforeach; ?>
                                    </select> 
                                    <button type="button" class="btn btn-small btn-add-step">+ Add</button>
                                </div>
                                <ul class="routeList"></ul>
                                <input type="hidden" name="voucher_type_workflow" class="workflowInput" value="<?php echo htmlspecialchars($v_type['default_workflow'] ?? '[]', ENT_QUOTES); ?>">
                            </div>
                        </div>
                        <div class="edit-actions">
                            <button type="submit" name="update_voucher_type" class="btn btn-small btn-gold">Save Changes</button>
                            <button type="button" class="btn btn-small btn-cancel" onclick="toggleVoucherEditView(<?php echo $v_type['id']; ?>)">Cancel</button>
                        </div>
                    </form>
                    <form method="POST" onsubmit="return confirm('Are you sure you want to permanently delete \'<?php echo htmlspecialchars($v_type['name']); ?>\'? This cannot be undone.');" style="position: absolute; bottom: 20px; right: 20px;">
                        <input type="hidden" name="voucher_type_id" value="<?php echo $v_type['id']; ?>">
                        <button type="submit" name="delete_voucher_type" class="btn btn-small btn-delete-single">Delete</button>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>
        <?php if (empty($all_voucher_types)): ?>
            <p style="text-align: center; color: var(--text-muted); padding: 20px; background: #f8fafc; border-radius: 6px;">No financial voucher types have been created yet.</p>
        <?php endif; ?>
    </div>
    <div class="card-footer">
        <div class="bulk-actions">
            <button type="submit" form="bulkDeleteVoucherTypeForm" class="btn btn-small btn-delete">Delete Selected</button>
        </div>
    </div>
</div>