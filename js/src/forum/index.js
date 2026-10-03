import app from 'flarum/forum/app';
import { extend } from 'flarum/common/extend';
import DiscussionControls from 'flarum/forum/utils/DiscussionControls';
import Button from 'flarum/common/components/Button';
import ExportModal from './components/ExportModal';

app.initializers.add('ernestdefoe-folio', () => {
  extend(DiscussionControls, 'userControls', function (items, discussion) {
    const formats = app.forum.attribute('folioFormats') || [];

    if (!discussion.attribute('canFolioExport') || !formats.length) return;

    items.add(
      'folio-export',
      <Button icon="fas fa-file-export" onclick={() => app.modal.show(ExportModal, { discussion })}>
        {app.translator.trans('ernestdefoe-folio.forum.export')}
      </Button>
    );
  });
});
