import '../css/groups-visualization.scss';
import * as d3 from 'd3';

class GroupsVisualization {
    constructor() {
        this.width = 1200;
        this.height = 800;
        this.margin = { top: 20, right: 20, bottom: 20, left: 20 };
        
        this.svg = null;
        this.simulation = null;
        this.nodes = [];
        this.links = [];
        this.allNodes = [];
        this.allLinks = [];
        this.searchTerm = '';
        this.visualizationInstance = null;
        
        this.init();
    }
    
    async init() {
        this.createSVG();
        await this.loadData();
        this.createSimulation();
        this.render();
        this.setupControls();
    }
    
    createSVG() {
        const container = d3.select('#groups-graph');
        
        // Remove loading indicator
        container.select('.loading').remove();
        
        this.svg = container
            .append('svg')
            .attr('width', this.width)
            .attr('height', this.height)
            .call(d3.zoom()
                .scaleExtent([0.1, 4])
                .on('zoom', (event) => {
                    this.svg.select('g').attr('transform', event.transform);
                })
            );
        
        // Create main group for all elements
        this.mainGroup = this.svg.append('g');
        
        // Create groups for links and nodes
        this.linksGroup = this.mainGroup.append('g').attr('class', 'links');
        this.nodesGroup = this.mainGroup.append('g').attr('class', 'nodes');
    }
    
    async loadData() {
        try {
            const response = await fetch('/api/groups/graph-data');
            const data = await response.json();
            
            this.allNodes = data.nodes.map(d => ({ ...d, radius: this.getNodeRadius(d) }));
            this.allLinks = data.links.map(d => ({ ...d }));
            this.nodes = [...this.allNodes];
            this.links = [...this.allLinks];
            
            console.log('Loaded data:', { nodes: this.nodes.length, links: this.links.length });
        } catch (error) {
            console.error('Error loading graph data:', error);
        }
    }
    
    createSimulation() {
        this.simulation = d3.forceSimulation(this.nodes)
            .force('link', d3.forceLink(this.links)
                .id(d => d.id)
                .distance(120)
                .strength(0.7)
            )
            .force('charge', d3.forceManyBody()
                .strength(-400)
                .distanceMax(250)
            )
            .force('center', d3.forceCenter(this.width / 2, this.height / 2))
            .force('x', d3.forceX(this.width / 2).strength(0.1))
            .force('y', d3.forceY(this.height / 2).strength(0.1))
            .force('collision', d3.forceCollide()
                .radius(d => this.getNodeRadius(d) + 15)
                .strength(0.9)
            )
            .alphaTarget(0);
    }
    
    getNodeRadius(d) {
        const baseRadius = d.isImportant ? 50 : 40;
        const memberBonus = Math.sqrt(d.memberCount || 0) * 2;
        return Math.min(baseRadius + memberBonus, 70);
    }
    
    getNodeColor(d) {
        if (d.isImportant) {
            return '#0B885D'; // Sea Green (couleur primaire RNF) pour les groupes importants
        }
        
        switch (d.visibility) {
            case 'public':
                return '#C8D469'; // Straw (couleur secondaire)
            case 'open':
                return '#31B7BC'; // Maximum Blue Green
            case 'moderate':
                return '#FABB64'; // Yellow Orange
            case 'restricted':
                return '#518196'; // Teal Blue
            default:
                return '#646363'; // Grey Neutral
        }
    }
    
    render() {
        // Create defs section for reusable elements
        const defs = this.svg.append('defs');
        
        // Define arrow markers for parent-child relationships
        defs.append('marker')
            .attr('id', 'arrowhead')
            .attr('viewBox', '-0 -5 10 10')
            .attr('refX', 13)
            .attr('refY', 0)
            .attr('orient', 'auto')
            .attr('markerWidth', 13)
            .attr('markerHeight', 13)
            .attr('xoverflow', 'visible')
            .append('svg:path')
            .attr('d', 'M 0,-5 L 10 ,0 L 0,5')
            .attr('fill', '#8E8F94')
            .style('stroke', 'none');

        // Create image patterns for nodes with logos
        this.nodes.forEach(d => {
            if (d.logoUrl) {
                const patternId = `image-pattern-${d.id}`;
                const pattern = defs.append('pattern')
                    .attr('id', patternId)
                    .attr('patternUnits', 'objectBoundingBox')
                    .attr('width', 1)
                    .attr('height', 1);
                
                pattern.append('image')
                    .attr('href', d.logoUrl)
                    .attr('width', this.getNodeRadius(d) * 2)
                    .attr('height', this.getNodeRadius(d) * 2)
                    .attr('x', 0)
                    .attr('y', 0)
                    .attr('preserveAspectRatio', 'xMidYMid slice');
            }
        });

        // Render links
        const link = this.linksGroup
            .selectAll('line')
            .data(this.links)
            .enter()
            .append('line')
            .attr('class', 'link')
            .attr('stroke', '#8E8F94')
            .attr('stroke-opacity', 0.6)
            .attr('stroke-width', 2)
            .attr('marker-end', 'url(#arrowhead)');
        
        // Render nodes
        const node = this.nodesGroup
            .selectAll('g')
            .data(this.nodes)
            .enter()
            .append('g')
            .attr('class', 'node')
            .call(this.createDrag());
        
        // Add circles for nodes
        node.append('circle')
            .attr('r', d => this.getNodeRadius(d))
            .attr('fill', d => {
                if (d.logoUrl) {
                    return `url(#image-pattern-${d.id})`;
                }
                return this.getNodeColor(d);
            })
            .attr('stroke', d => d.isImportant ? '#0B885D' : '#fff')
            .attr('stroke-width', d => d.isImportant ? 4 : 2);
        
        // Add labels with text wrapping
        const labels = node.append('text')
            .attr('text-anchor', 'middle')
            .attr('font-size', d => Math.max(9, this.getNodeRadius(d) / 5))
            .attr('font-weight', d => d.isImportant ? 'bold' : 'bold')
            .attr('fill', d => d.logoUrl ? '#fff' : '#fff')
            .attr('stroke', d => d.logoUrl ? 'rgba(0,0,0,0.9)' : 'rgba(0,0,0,0.7)')
            .attr('stroke-width', d => d.logoUrl ? 1.2 : 0.8)
            .attr('stroke-linejoin', 'round')
            .attr('stroke-linecap', 'round')
            .attr('paint-order', 'stroke fill')
            .style('text-shadow', d => d.logoUrl ? '2px 2px 2px rgba(0,0,0,0.8)' : '1px 1px 1px rgba(0,0,0,0.5)')
            .attr('pointer-events', 'none');

        labels.each(function(d) {
            const text = d3.select(this);
            const words = d.name.split(/\s+/);
            const lineHeight = 1.1; // ems
            const radius = d.radius || 40;
            const maxWidth = radius * 2.2;
            
            let line = [];
            let lineNumber = 0;
            let tspan = text.append('tspan').attr('x', 0).attr('y', 0);
            
            words.forEach(word => {
                line.push(word);
                tspan.text(line.join(' '));
                if (tspan.node().getComputedTextLength() > maxWidth && line.length > 1) {
                    line.pop();
                    tspan.text(line.join(' '));
                    line = [word];
                    tspan = text.append('tspan')
                        .attr('x', 0)
                        .attr('y', ++lineNumber * lineHeight + 'em')
                        .text(word);
                }
            });
            
            // Center the text vertically
            const totalLines = text.selectAll('tspan').size();
            text.attr('dy', `${-((totalLines - 1) * lineHeight) / 2}em`);
        });

        // Add importance badges for important groups
        node.filter(d => d.isImportant)
            .append('circle')
            .attr('r', 12)
            .attr('cx', d => this.getNodeRadius(d) * 0.7)
            .attr('cy', d => -this.getNodeRadius(d) * 0.7)
            .attr('fill', '#fff')
            .attr('stroke', '#0B885D')
            .attr('stroke-width', 2);

        node.filter(d => d.isImportant)
            .append('image')
            .attr('href', '/media/favicon/favicon-32x32.png')
            .attr('x', d => this.getNodeRadius(d) * 0.7 - 8)
            .attr('y', d => -this.getNodeRadius(d) * 0.7 - 8)
            .attr('width', 16)
            .attr('height', 16)
            .attr('pointer-events', 'none');
        
        // Add tooltips
        node
            .on('mouseover', (event, d) => this.showTooltip(event, d))
            .on('mouseout', () => this.hideTooltip())
            .on('click', (event, d) => {
                window.open(d.url, '_blank');
            });
        
        // Update positions on simulation tick
        this.simulation.on('tick', () => {
            link.each(function(d) {
                const dx = d.target.x - d.source.x;
                const dy = d.target.y - d.source.y;
                const dr = Math.sqrt(dx * dx + dy * dy);
                const offsetX = (dx * d.target.radius) / dr;
                const offsetY = (dy * d.target.radius) / dr;
                
                d3.select(this)
                    .attr('x1', d.source.x)
                    .attr('y1', d.source.y)
                    .attr('x2', d.target.x - offsetX)
                    .attr('y2', d.target.y - offsetY);
            });
            
            node
                .attr('transform', d => `translate(${d.x},${d.y})`);
        });
    }
    
    createDrag() {
        return d3.drag()
            .on('start', (event, d) => {
                if (!event.active) this.simulation.alphaTarget(0.3).restart();
                d.fx = d.x;
                d.fy = d.y;
            })
            .on('drag', (event, d) => {
                d.fx = event.x;
                d.fy = event.y;
            })
            .on('end', (event, d) => {
                if (!event.active) this.simulation.alphaTarget(0);
                d.fx = null;
                d.fy = null;
            });
    }
    
    showTooltip(event, d) {
        const tooltip = d3.select('#node-tooltip');
        
        tooltip.select('.tooltip-title').text(d.name);
        tooltip.select('.tooltip-description').text(d.description || 'Aucune description');
        tooltip.select('.member-count').text(`${d.memberCount || 0} membre(s)`);
        tooltip.select('.visibility').text(`Visibilité: ${d.visibility}`);
        
        tooltip
            .style('display', 'block')
            .style('left', (event.pageX + 10) + 'px')
            .style('top', (event.pageY - 10) + 'px');
    }
    
    hideTooltip() {
        d3.select('#node-tooltip').style('display', 'none');
    }
    
    setupControls() {
        const centerGraphBtn = document.getElementById('center-graph');
        if (centerGraphBtn) {
            centerGraphBtn.addEventListener('click', () => {
                this.simulation.alpha(1).restart();
            });
        }
        
        // Connect search functionality
        this.setupSearch();
    }
    
    setupSearch() {
        // Store reference to this visualization instance
        window.currentVisualization = this;
        
        // We'll connect to the search input when it exists
        const connectSearch = () => {
            const searchInput = document.querySelector('input[name="groups_search_bar"]');
            if (searchInput && !searchInput.hasAttribute('data-visualization-connected')) {
                searchInput.setAttribute('data-visualization-connected', 'true');
                let searchTimeout;
                
                searchInput.addEventListener('input', (e) => {
                    clearTimeout(searchTimeout);
                    searchTimeout = setTimeout(() => {
                        if (window.currentVisualization) {
                            window.currentVisualization.filterNodes(e.target.value);
                        }
                    }, 300);
                });
                
                // Apply current search value if any
                if (searchInput.value) {
                    this.filterNodes(searchInput.value);
                }
            }
        };
        
        // Try to connect immediately and after a delay
        connectSearch();
        setTimeout(connectSearch, 500);
    }
    
    filterNodes(searchTerm) {
        this.searchTerm = searchTerm.toLowerCase().trim();
        
        if (!this.searchTerm) {
            // Reset to show all nodes
            this.nodes = [...this.allNodes];
            this.links = [...this.allLinks];
        } else {
            // Find nodes that match the search term
            const matchingNodeIds = new Set();
            this.allNodes.forEach(node => {
                if (node.name.toLowerCase().includes(this.searchTerm) || 
                    (node.description && node.description.toLowerCase().includes(this.searchTerm))) {
                    matchingNodeIds.add(node.id);
                }
            });
            
            // Find all nodes connected to matching nodes
            const connectedNodeIds = new Set(matchingNodeIds);
            let changed = true;
            while (changed) {
                changed = false;
                this.allLinks.forEach(link => {
                    if (connectedNodeIds.has(link.source.id || link.source) && 
                        !connectedNodeIds.has(link.target.id || link.target)) {
                        connectedNodeIds.add(link.target.id || link.target);
                        changed = true;
                    }
                    if (connectedNodeIds.has(link.target.id || link.target) && 
                        !connectedNodeIds.has(link.source.id || link.source)) {
                        connectedNodeIds.add(link.source.id || link.source);
                        changed = true;
                    }
                });
            }
            
            // Filter nodes and links
            this.nodes = this.allNodes.filter(node => connectedNodeIds.has(node.id));
            this.links = this.allLinks.filter(link => 
                connectedNodeIds.has(link.source.id || link.source) && 
                connectedNodeIds.has(link.target.id || link.target)
            );
        }
        
        // Update visualization
        this.updateVisualization();
    }
    
    updateVisualization() {
        // Remove existing elements
        this.nodesGroup.selectAll('*').remove();
        this.linksGroup.selectAll('*').remove();
        
        // Clear existing patterns from defs
        this.svg.select('defs').selectAll('pattern').remove();
        
        // Recreate simulation with filtered data
        this.createSimulation();
        this.render();
    }
}

// Expose class globally for external usage
window.GroupsVisualization = GroupsVisualization;