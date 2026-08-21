import domready from 'mf-js/modules/dom/ready';

// Les deux axes de tri de la liste des groupes : de qui un groupe dépend, et
// de quoi il parle. Ils se cumulent, donc chacun doit conserver l'autre. (#23)
const FILTERS = [
	{ id: 'groups-filter-commission', parameter: 'commission' },
	{ id: 'groups-filter-theme', parameter: 'theme' },
];

domready( () => {
	const input = document.getElementById( 'form_groups_search_bar' );
	if(input!==null){
		input.addEventListener('input', delay(searchGroups, 500) );
	}

	// On recharge la liste sans quitter la page, et le texte déjà saisi est
	// conservé.
	FILTERS.forEach( ( filter ) => {
		const select = document.getElementById( filter.id );

		if(select===null){
			return;
		}

		select.addEventListener('change', () => {
			const url = new URL(window.location.href);

			if(select.value){
				url.searchParams.set(filter.parameter, select.value);
			} else {
				url.searchParams.delete(filter.parameter);
			}

			window.history.replaceState({}, '', url);

			const text = input !== null ? input.value : '';
			getGroupHTML('groups-to-activate-elements', text);
			getGroupHTML('all-groups-container', text);
		});
	});
});

async function searchGroups(e){
	getGroupHTML('groups-to-activate-elements', e.target.value);
	getGroupHTML('all-groups-container', e.target.value);
}

function currentFilters(){
	return FILTERS.map( ( filter ) => {
		const select = document.getElementById( filter.id );
		const value  = select !== null && select.value ? select.value : '';

		return "&" + filter.parameter + "=" + encodeURIComponent(value);
	} ).join('');
}

async function getGroupHTML(id, text){
	const groups = document.getElementById( id );
	if(groups!==null){
		const searchType = id;
		const newGroupsObject = await fetch(
				"/groups/search?type=" + encodeURIComponent(searchType)
				+ "&q=" + encodeURIComponent(text)
				+ currentFilters()
		).then(response => response.json());
		groups.innerHTML = newGroupsObject.groups;
	}
}

function delay(fn, ms) {
	let timer = 0;
	return function(...args) {
	  clearTimeout(timer)
	  timer = setTimeout(fn.bind(this, ...args), ms || 0)
	}
}
