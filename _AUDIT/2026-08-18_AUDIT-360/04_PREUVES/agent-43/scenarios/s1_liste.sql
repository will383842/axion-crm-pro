SET app.current_workspace_id = '20cd81e4-de5d-4875-a759-07d64fe1f168';
SELECT companies.* FROM companies
WHERE deleted_at IS NULL AND workspace_id = '20cd81e4-de5d-4875-a759-07d64fe1f168'::uuid
  AND (lifecycle_stage <> 'nouveau' OR EXISTS (SELECT 1 FROM company_tag JOIN tags ON tags.id = company_tag.tag_id
       WHERE company_tag.company_id = companies.id AND tags.slug NOT LIKE 'src:scraping-%' AND tags.slug LIKE 'src:%'))
ORDER BY updated_at DESC, id DESC LIMIT 51;
