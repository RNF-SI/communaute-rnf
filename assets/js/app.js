/**************************************************
 * CSS
 **************************************************/
//
import '../css/app.scss';
import 'quill/dist/quill.snow.css';
//
/**************************************************
 * POLYFILLS
 **************************************************/
//
import 'core-js/features/object/assign';
import 'core-js/features/object/values';
import 'core-js/features/array/from';
import 'core-js/features/array/for-each';
import 'core-js/features/promise';
//
/**************************************************
 * COMPONENTS
 **************************************************/
//
import './interaction/interactive-submission';
// 
import './map/map-communaute';
import './map/map-home';
import './map/map-home-region';
//
import './ui/home';
import './ui/input-autocomplete';
import './ui/input-checkboxes-autocomplete';
import './ui/input-file-preview';
import './ui/input-file-name';
import './ui/input-file-prefill';
import './ui/see-more';
import './ui/element-toggle';
import './ui/wysiwyg';
import './ui/tour';
import './ui/documents-folding';
import './ui/url-to-link';
import './ui/confirm';
import './ui/copy-to-clipboard';
import './ui/prevent-double-submit';
import './ui/oembed-to-iframe';
import './ui/removable-tag-list';
import './ui/groups-search';
import './ui/add-form';
import './ui/link-order-change';
import './ui/message-tags';
import './ui/tag-picker';
import './ui/onlyoffice';
//
// Le direct de la messagerie. `badge` s'abonne au chargement du module,
// `dock` allume le moteur : dans cet ordre, sans quoi la pastille manquerait
// le premier tour.
import './messaging/badge';
import './messaging/dock';
//
import './user/profile';
import './user/dashboard';
import './user/notifications-settings';

console.warn( 'Hello fellow developer, ENV is dev.\nDont\'t forget to compile in production mode before deploying.\nHappy coding ! Max.' );
