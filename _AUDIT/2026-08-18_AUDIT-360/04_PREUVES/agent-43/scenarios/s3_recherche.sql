SET app.current_workspace_id = '20cd81e4-de5d-4875-a759-07d64fe1f168';
SELECT id, denomination FROM companies
WHERE workspace_id = '20cd81e4-de5d-4875-a759-07d64fe1f168'::uuid AND deleted_at IS NULL
  AND denomination ILIKE 'Cabinet Mar%' ORDER BY updated_at DESC LIMIT 20;
