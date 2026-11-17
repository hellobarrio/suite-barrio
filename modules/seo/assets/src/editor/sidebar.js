// La sidebar SEO dell'editor a blocchi (src/Editor.php), con il punteggio sull'icona: i meta si salvano
// con il contenuto.
import { useDispatch, useSelect } from '@wordpress/data';
import {
	PluginSidebar,
	PluginSidebarMoreMenuItem,
	store as editorStore,
} from '@wordpress/editor';
import { registerPlugin } from '@wordpress/plugins';

import { Badge, Panel, analyze } from './Panel.js';
import './editor.css';

const context = window.barrioSeo;

function Sidebar() {
	const { meta, ...page } = useSelect( ( select ) => {
		const editor = select( editorStore );
		return {
			meta: editor.getEditedPostAttribute( 'meta' ),
			title: editor.getEditedPostAttribute( 'title' ),
			excerpt: editor.getEditedPostAttribute( 'excerpt' ),
			content: editor.getEditedPostContent(),
			password: editor.getEditedPostAttribute( 'password' ),
			featured: editor.getEditedPostAttribute( 'featured_media' ),
			url: editor.getPermalink(),
		};
	}, [] );
	const { editPost } = useDispatch( editorStore );
	const seo = analyze( meta, page, context );
	const icon = <Badge value={ seo.result.score } />;
	return (
		<>
			<PluginSidebarMoreMenuItem target="barrio-seo" icon={ icon }>
				SEO
			</PluginSidebarMoreMenuItem>
			<PluginSidebar name="barrio-seo" title="SEO" icon={ icon }>
				<Panel
					meta={ meta }
					page={ page }
					context={ context }
					seo={ seo }
					onChange={ ( changes ) => editPost( { meta: changes } ) }
				/>
			</PluginSidebar>
		</>
	);
}

registerPlugin( 'barrio-seo', { render: Sidebar } );
