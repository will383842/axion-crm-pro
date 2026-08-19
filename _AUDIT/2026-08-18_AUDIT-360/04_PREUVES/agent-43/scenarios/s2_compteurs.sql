SET app.current_workspace_id = '20cd81e4-de5d-4875-a759-07d64fe1f168';
SELECT relation_type, lifecycle_stage, count(*) FROM companies
WHERE workspace_id = '20cd81e4-de5d-4875-a759-07d64fe1f168'::uuid AND deleted_at IS NULL
GROUP BY relation_type, lifecycle_stage;
