<?php
require_once ("../xhprof_lib/config.php");


if (false !== $controlIPs && !in_array($_SERVER['REMOTE_ADDR'], $controlIPs))
{
	die("You do not have permission to view this page.");
}

// by default assume that xhprof_html & xhprof_lib directories
// are at the same level.
if (!defined('XHPROF_LIB_ROOT')) {
	define('XHPROF_LIB_ROOT', dirname(dirname(__FILE__)) . '/xhprof_lib');
}

include_once XHPROF_LIB_ROOT . '/display/xhprof.php';
ini_set('max_execution_time', 100);

$params = array(// run id param
	'run' => array(XHPROF_STRING_PARAM, ''),
	'source' => array(XHPROF_STRING_PARAM, 'xhprof'),
	'threshold' => array(XHPROF_FLOAT_PARAM, 2000),
	'metric' => array(XHPROF_STRING_PARAM, 'cpu'),
);
xhprof_param_init($params);

if (isset($_GET['data'])) {
	
	$totals;
	$xhprof_runs_impl = new XHProfRuns_Default();
	$raw_data = $xhprof_runs_impl->get_run($run, $totals, $desc_unused);
	$raw_data = $raw_data[0];
	
	$raw_data_threshold = array();
	foreach ($raw_data as $parent_child=>$info) {
		if ($info[$metric] > $threshold) {
			$raw_data_threshold[$parent_child] = $info;
		}
	}
	
	$raw_data = $raw_data_threshold;
	
	$totals = array();
	
	$sym_table = xhprof_compute_flat_info($raw_data, $totals);
	$nodes = array();
	$links = array();
	$nodesKeys = array();
	$nodeKey = 0;
	$max = 0;
	$min = null;
	$minSym = null;
	$maxSym = 0;
	
	foreach ($sym_table as $symbol => $info) {
		$tmp = $info;
		$tmp['fn'] = $symbol;
		$aggr = _aggregateCalls(array($tmp));
		$aggrKeys = array_keys($aggr);
		if (isset($aggrKeys[0]) && !is_numeric($aggrKeys[0])) {
			$group = $aggr[$aggrKeys[0]]['fn'];
		} else {
			$group = 'others';
		}
		
		$nodes[$nodeKey] = array(
			'name' => $symbol,
			'group' => $group,
			'value' => $info['excl_'.$metric],
		);
		$nodesKeys[$symbol] = $nodeKey;
		
		if ($info['excl_'.$metric] > $maxSym) {
			$maxSym = $info['excl_'.$metric];
		}
		if ($info['excl_'.$metric] < $minSym || $minSym === null) {
			$minSym = $info['excl_'.$metric];
		}
		
		$nodeKey++;
	}
	
	foreach ($raw_data as $parent_child => $info) {
		if ($info[$metric] > $max) {
			$max = $info[$metric];
		}
		if ($info[$metric] < $min || $min === null) {
			$min = $info[$metric];
		}
		
		list($parent, $child) = xhprof_parse_parent_child($parent_child);
		if (isset($nodesKeys[$parent]) && isset($nodesKeys[$child])) {
			$links[] = array(
				'source' => $nodesKeys[$parent],
				'target' => $nodesKeys[$child],
				'value' => $info[$metric],
			);
		}
	}
	$info = array('min'=>$min, 'max'=>$max, 'minSym'=>$minSym, 'maxSym'=>$maxSym);
	
	$data = array('nodes' => $nodes, 'links' => $links, 'info' => $info);
	header('Content-Type: application/json');
	echo json_encode($data);
} else {
	require_once ("../xhprof_lib/templates/header.phtml");

?>
<style>
circle.node {
  stroke: #fff;
  stroke-width: 1.5px;
}

line.link {
  stroke: #999;
  stroke-opacity: .6;
}
#chart {
	border: 1px solid black;
}

</style>
<br />
<form>
Metric : 
<select name="metric" id="metric">
	<option value="wt" selected="selected" <?php echo $metric=='wt' ? 'selected="selected"' : ''?>>Time</option>
	<option value="cpu" <?php echo $metric=='cpu' ? 'selected="selected"' : ''?>>Cpu</option>
	<option value="mu" <?php echo $metric=='mu' ? 'selected="selected"' : ''?>>Memory</option>
</select>
Threshold :<input type="text" name="threshold" id="threshold" value="<?php echo $threshold; ?>" size="5" />
<input type="submit" value="change" />
<input type="hidden" value="<?php echo $run?>" name="run" />
</form>

<div id="chart"></div>
<script type="text/javascript">

var width = 1300,
height = 1000;

var color = d3.scale.category20();

var force = d3.layout.force()
	.charge(-120)
	.linkDistance(30)
	.size([width, height]);

var svg = d3.select("#chart").append("svg")
	.attr("width", width)
	.attr("height", height);

load('<?php echo $metric?>', '<?php echo $threshold; ?>');
	
function load(metric, threshold) {
	d3.json("execviz.php?run=<?php echo $_GET['run']?>&data&metric="+metric+"&threshold="+threshold, function(json) {
	
		var w = d3.scale.linear()
		    .domain([json.info.min, json.info.max])
		    .range([1, 20]);
		var widthSym = d3.scale.linear()
		    .domain([json.info.minSym, json.info.maxSym])
		    .range([2, 20]);
	
		force
		  .nodes(json.nodes)
		  .links(json.links)
		  .start();

		
		var link = svg.selectAll("line.link")
			  .data(json.links)
			.enter().append("line")
			  .attr("class", "link")
			  .style("stroke-width", function(d) { return w(d.value);});
		  
		var node = svg.selectAll("circle.node")
			  .data(json.nodes)
			.enter().append("circle")
			  .attr("class", "node")
			  .attr("r", function(d) { return widthSym(d.value)})
			  .style("fill", function(d) { return color(d.group); })
			  .call(force.drag);
			
		node.append("title")
		  .text(function(d) { return d.name+' ('+d.value+')'; });
	
		force.on("tick", function() {
			link.attr("x1", function(d) { return d.source.x; })
			    .attr("y1", function(d) { return d.source.y; })
			    .attr("x2", function(d) { return d.target.x; })
			    .attr("y2", function(d) { return d.target.y; });
		    
			node.attr("cx", function(d) { return d.x; })
			    .attr("cy", function(d) { return d.y; });
		});
	});
}

</script>
<?php }?>