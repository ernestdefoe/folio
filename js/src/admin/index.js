import app from 'flarum/admin/app';

const t = (key, params) => app.translator.trans(`ernestdefoe-folio.admin.${key}`, params);

/*
 * The help text names placeholders like {title}, and the translator reads
 * braces as ITS placeholders: unfilled, they print "{undefined}". ICU quoting
 * is not honoured here, so each one is passed in as its own literal name.
 */
const placeholders = { forum: '{forum}', title: '{title}', date: '{date}', url: '{url}', id: '{id}' };

app.initializers.add('ernestdefoe-folio', () => {
  app.registry
    .for('ernestdefoe-folio')
    .registerPermission(
      {
        icon: 'fas fa-file-export',
        label: t('permission'),
        permission: 'discussion.folioExport',
        tagScoped: true,
      },
      'view'
    )
    .registerSetting(() => <h3 className="FolioAdmin-heading">{t('formats_heading')}</h3>)
    .registerSetting({ setting: 'ernestdefoe-folio.format_pdf', type: 'boolean', label: t('format_pdf') })
    .registerSetting({ setting: 'ernestdefoe-folio.format_docx', type: 'boolean', label: t('format_docx') })
    .registerSetting({ setting: 'ernestdefoe-folio.format_markdown', type: 'boolean', label: t('format_markdown') })
    .registerSetting(() => <h3 className="FolioAdmin-heading">{t('document_heading')}</h3>)
    .registerSetting({
      setting: 'ernestdefoe-folio.paper',
      type: 'select',
      label: t('paper'),
      options: { a4: t('paper_a4'), letter: t('paper_letter') },
      default: 'a4',
    })
    .registerSetting({ setting: 'ernestdefoe-folio.header', type: 'text', label: t('header'), help: t('header_help', placeholders) })
    .registerSetting({ setting: 'ernestdefoe-folio.footer', type: 'text', label: t('footer'), help: t('footer_help') })
    .registerSetting({ setting: 'ernestdefoe-folio.filename', type: 'text', label: t('filename'), help: t('filename_help', placeholders), placeholder: '{title}' })
    .registerSetting({ setting: 'ernestdefoe-folio.max_posts', type: 'number', min: 1, label: t('max_posts'), help: t('max_posts_help') })
    .registerSetting({ setting: 'ernestdefoe-folio.remote_images', type: 'boolean', label: t('remote_images'), help: t('remote_images_help') })
    .registerSetting({ setting: 'ernestdefoe-folio.custom_css', type: 'textarea', label: t('custom_css'), help: t('custom_css_help') });
});
